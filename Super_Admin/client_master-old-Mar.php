<?php
require 'db.php';
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
if (session_status() === PHP_SESSION_NONE) { session_start(); }

// ---------------------------------------------------------
// HELPER FUNCTIONS
// ---------------------------------------------------------
function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function formatDate($d) {
    if (empty($d) || $d === '0000-00-00' || $d === '0000-00-00 00:00:00') return '—';
    return date('d-m-Y', strtotime($d));
}
function redirect_with($params = []) {
    $base = 'client_master.php';
    if (!empty($params)) $base .= '?' . http_build_query($params);
    header("Location: $base");
    exit;
}

// ---------------------------------------------------------
// HANDLE MANUAL FORM SUBMISSIONS
// ---------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $client_name = trim($_POST['client_name'] ?? '');

    // Basic validation
    if (($action === 'add' || $action === 'edit') && $client_name === '') {
        redirect_with(['msg'=>'Client Name is required.','type'=>'danger']);
    }

    if ($action === 'add') {
        // Sanitize/Format all input variables
        $client_name = $mysqli->real_escape_string(trim($_POST['client_name'] ?? ''));
        $initial = $mysqli->real_escape_string(trim($_POST['initial'] ?? ''));
        $company = $mysqli->real_escape_string(trim($_POST['company_name'] ?? ''));
        $contact = $mysqli->real_escape_string(trim($_POST['contact_person'] ?? ''));
        $email = $mysqli->real_escape_string(trim($_POST['email'] ?? ''));
        $general_email = $mysqli->real_escape_string(trim($_POST['general_email'] ?? ''));
        $invoice_email = $mysqli->real_escape_string(trim($_POST['invoice_email'] ?? ''));
        $production_email = $mysqli->real_escape_string(trim($_POST['production_email'] ?? ''));
        $phone = $mysqli->real_escape_string(trim($_POST['phone'] ?? ''));
        $additional_mobile = $mysqli->real_escape_string(trim($_POST['additional_mobile'] ?? ''));
        $address = $mysqli->real_escape_string(trim($_POST['address'] ?? ''));
        $additional_address = $mysqli->real_escape_string(trim($_POST['additional_address'] ?? ''));
        $country = $mysqli->real_escape_string(trim($_POST['country'] ?? 'India'));
        $gst = $mysqli->real_escape_string(trim($_POST['gst_no'] ?? ''));
        $pref = $mysqli->real_escape_string(trim($_POST['preferred_communication'] ?? ''));
        $language = $mysqli->real_escape_string(trim($_POST['preferred_language'] ?? ''));
        $paypal_email = $mysqli->real_escape_string(trim($_POST['paypal_email'] ?? ''));
        $currency = $mysqli->real_escape_string(trim($_POST['currency'] ?? ($country === 'India' ? 'INR' : 'USD')));
        $nda = $mysqli->real_escape_string(trim($_POST['nda'] ?? ''));
        $date = $mysqli->real_escape_string(trim($_POST['date'] ?? date('Y-m-d')));
        $status = 'Active';

        // Numbers
        $word_rate = floatval($_POST['word_rate'] ?? 0);
        $page_rate = floatval($_POST['page_rate'] ?? 0);
        $math_rate = floatval($_POST['math_rate'] ?? 0);
        $ref_rate = floatval($_POST['ref_rate'] ?? 0);
        $gst_per = floatval($_POST['gst_per'] ?? 0);
        $cgst = floatval($_POST['cgst'] ?? 0);
        $igst = floatval($_POST['igst'] ?? 0);
        $paypal_percentage = floatval($_POST['paypal_percentage'] ?? 0);

        // Apply country-based logic
        if (strtolower($country) === 'india') {
            // For India: Check if address contains Tamil Nadu
            $address_lower = strtolower($address);
            if (strpos($address_lower, 'tamil') !== false || strpos($address_lower, 'tamilnadu') !== false || strpos($address_lower, 'tamil nadu') !== false) {
                // Tamil Nadu: Enable CGST/IGST, disable PayPal
                $paypal_percentage = 0;
                $paypal_email = '';
            } else {
                // Other Indian states: Enable GST only, disable CGST/IGST
                $cgst = 0;
                $igst = 0;
            }
        } else {
            // Non-India: Enable PayPal only, disable GST fields
            $gst = '';
            $gst_per = 0;
            $cgst = 0;
            $igst = 0;
        }

        $sql = "INSERT INTO client_master 
            (client_name, initial, company_name, contact_person, email, general_email, invoice_email, production_email, phone, additional_mobile, address, additional_address, country, gst_no, cgst, igst, paypal_email, paypal_percentage, preferred_communication, preferred_language, created_date, status, word_rate, page_rate, math_rate, ref_rate, gst_per, currency, nda, date) 
            VALUES (
                '$client_name', 
                '$initial', 
                '$company', 
                '$contact', 
                '$email', 
                '$general_email', 
                '$invoice_email', 
                '$production_email', 
                '$phone', 
                '$additional_mobile', 
                '$address', 
                '$additional_address', 
                '$country', 
                '$gst', 
                $cgst, 
                $igst, 
                '$paypal_email', 
                $paypal_percentage, 
                '$pref', 
                '$language', 
                NOW(), 
                '$status', 
                $word_rate, 
                $page_rate, 
                $math_rate, 
                $ref_rate, 
                $gst_per, 
                '$currency',
                '$nda',
                '$date'
            )";
        
        if ($mysqli->query($sql)) {
            redirect_with(['msg'=>'Client added successfully!','type'=>'success']);
        } else {
            redirect_with(['msg'=>'Error adding client: ' . $mysqli->error,'type'=>'danger']);
        }
    }
    
    if ($action === 'edit') {
        $id = (int)($_POST['client_id'] ?? 0);
        if ($id > 0) {
            $client_name = trim($_POST['client_name'] ?? '');
            $initial = trim($_POST['initial'] ?? '');
            $company = trim($_POST['company_name'] ?? '');
            $contact = trim($_POST['contact_person'] ?? '');
            $email = trim($_POST['email'] ?? '');
            $general_email = trim($_POST['general_email'] ?? '');
            $invoice_email = trim($_POST['invoice_email'] ?? '');
            $production_email = trim($_POST['production_email'] ?? '');
            $phone = trim($_POST['phone'] ?? '');
            $additional_mobile = trim($_POST['additional_mobile'] ?? '');
            $address = trim($_POST['address'] ?? '');
            $additional_address = trim($_POST['additional_address'] ?? '');
            $country = trim($_POST['country'] ?? 'India');
            $gst = trim($_POST['gst_no'] ?? '');
            $pref = trim($_POST['preferred_communication'] ?? '');
            $language = trim($_POST['preferred_language'] ?? '');
            $paypal_email = trim($_POST['paypal_email'] ?? '');
            $ref_rate = (float)($_POST['ref_rate'] ?? 0);
            $nda = trim($_POST['nda'] ?? '');
            $date = trim($_POST['date'] ?? date('Y-m-d'));
            
            // Auto-select currency based on country
            $currency = trim($_POST['currency'] ?? ($country === 'India' ? 'INR' : 'USD'));
            
            $word_rate = floatval($_POST['word_rate'] ?? 0);
            $page_rate = floatval($_POST['page_rate'] ?? 0);
            $math_rate = floatval($_POST['math_rate'] ?? 0);
            $gst_per = floatval($_POST['gst_per'] ?? 0);
            $cgst = floatval($_POST['cgst'] ?? 0);
            $igst = floatval($_POST['igst'] ?? 0);
            $paypal_percentage = floatval($_POST['paypal_percentage'] ?? 0);

            // Apply country-based logic
            if (strtolower($country) === 'india') {
                // For India: Check if address contains Tamil Nadu
                $address_lower = strtolower($address);
                if (strpos($address_lower, 'tamil') !== false || strpos($address_lower, 'tamilnadu') !== false || strpos($address_lower, 'tamil nadu') !== false) {
                    // Tamil Nadu: Enable CGST/IGST, disable PayPal
                    $paypal_percentage = 0;
                    $paypal_email = '';
                } else {
                    // Other Indian states: Enable GST only, disable CGST/IGST
                    $cgst = 0;
                    $igst = 0;
                }
            } else {
                // Non-India: Enable PayPal only, disable GST fields
                $gst = '';
                $gst_per = 0;
                $cgst = 0;
                $igst = 0;
            }

            $stmt = $mysqli->prepare("UPDATE client_master SET
                client_name=?, initial=?, company_name=?, contact_person=?, email=?, general_email=?, invoice_email=?, production_email=?,
                phone=?, additional_mobile=?, address=?, additional_address=?, country=?, gst_no=?, preferred_communication=?, preferred_language=?,
                word_rate=?, page_rate=?, math_rate=?, ref_rate=?, gst_per=?, cgst=?, igst=?, currency=?, paypal_email=?, paypal_percentage=?, nda=?, date=?,
                created_date=NOW()
                WHERE client_id=?");
            
            if (!$stmt) {
                redirect_with(['msg'=>'Database error: ' . $mysqli->error,'type'=>'danger']);
            }
            
            $stmt->bind_param(
    "ssssssssssssssssdddddddssdssi",
    $client_name,          // s
    $initial,              // s
    $company,              // s
    $contact,              // s
    $email,                // s
    $general_email,        // s
    $invoice_email,        // s
    $production_email,     // s
    $phone,                // s
    $additional_mobile,    // s
    $address,              // s
    $additional_address,   // s
    $country,              // s
    $gst,                  // s
    $pref,                 // s
    $language,             // s
    $word_rate,            // d
    $page_rate,            // d
    $math_rate,            // d
    $ref_rate,             // d
    $gst_per,              // d
    $cgst,                 // d
    $igst,                 // d
    $currency,             // s
    $paypal_email,         // s
    $paypal_percentage,    // d
    $nda,                  // s
    $date,                 // s
    $id                    // i
);

            if ($stmt->execute()) {
                redirect_with(['msg'=>"Client '$client_name' updated successfully",'type'=>'success']);
            } else {
                redirect_with(['msg'=>'Error updating client: ' . $stmt->error,'type'=>'danger']);
            }
            $stmt->close();
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
        }
        redirect_with(['msg'=>"Client '$name' deleted",'type'=>'warning']);
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
                redirect_with(['msg'=>"Client '{$row['client_name']}' status changed to $newStatus",'type'=>'info']);
            }
        }
    }

    redirect_with();
}

// ---------------------------------------------------------
// VIEW / EDIT SETUP
// ---------------------------------------------------------
$mode = $_GET['mode'] ?? 'view';
$editClient = null;
if ($mode === 'edit') {
    $id = (int)($_GET['id'] ?? 0);
    if ($id > 0) {
        $stmt = $mysqli->prepare("SELECT * FROM client_master WHERE client_id=?");
        if ($stmt) {
            $stmt->bind_param("i", $id);
            $stmt->execute();
            $editClient = $stmt->get_result()->fetch_assoc();
            $stmt->close();
        }
    }
    if (!$editClient) $mode = 'view';
}

// Fetch languages from language_master
$languages = [];
$langResult = $mysqli->query("SELECT language_name FROM language_master WHERE status='Active' ORDER BY language_name");
if ($langResult) {
    while ($row = $langResult->fetch_assoc()) {
        $languages[] = $row['language_name'];
    }
} else {
    $languages = ['English', 'Hindi', 'Spanish', 'French', 'German'];
}

// Fetch currencies from currency_master
$currencies = [];
$currencyResult = $mysqli->query("SELECT currency_code, currency_name FROM currency_master WHERE status='Active' ORDER BY currency_code");
if ($currencyResult) {
    while ($row = $currencyResult->fetch_assoc()) {
        $currencies[$row['currency_code']] = $row['currency_name'];
    }
} else {
    $currencies = [
        'USD' => 'US Dollar',
        'INR' => 'Indian Rupee',
        'EUR' => 'Euro',
        'GBP' => 'British Pound'
    ];
}

// ---------------------------------------------------------
// FILTERS & SORTING
// ---------------------------------------------------------
$q = trim($_GET['q'] ?? '');
$fstatus = $_GET['status'] ?? '';
$sort = $_GET['sort'] ?? 'created_date';
$dir = strtolower($_GET['dir'] ?? 'desc');
$sortable = ['client_id','client_name','company_name','email','phone','status','created_date', 'word_rate', 'page_rate', 'math_rate', 'ref_rate', 'gst_per', 'nda', 'date'];
if (!in_array($sort, $sortable, true)) $sort = 'created_date';
$dir = ($dir === 'asc') ? 'asc' : 'desc';

// Build query - Different logic for passive clients
$sql = "SELECT cm.client_id, cm.client_name, cm.company_name, cm.contact_person,cm.initial, cm.email_files, cm.email, cm.general_email, cm.invoice_email, cm.production_email, cm.phone, cm.additional_mobile, cm.address, cm.additional_address, cm.country, cm.gst_no, cm.preferred_communication, cm.preferred_language, cm.status, cm.created_date, cm.word_rate, cm.page_rate, cm.math_rate, cm.ref_rate, cm.gst_per, cm.cgst, cm.igst, cm.currency, cm.paypal_email, cm.paypal_percentage, cm.nda, cm.date
     FROM client_master cm";

// Check if we need to filter for passive clients
$isPassive = ($fstatus === 'Passive');
if ($isPassive) {
    // Add LEFT JOIN and WHERE clause to find clients without jobs
    $sql .= " LEFT JOIN jobs j ON cm.client_id = j.client_id 
              WHERE j.client_id IS NULL";
} else {
    // Original WHERE clause for other filters
    $sql .= " WHERE 1=1";
}

$cond = []; $params = []; $types = '';
if ($q !== '' && !$isPassive) {
    $cond[] = "(cm.client_name LIKE ? OR cm.company_name LIKE ? OR cm.contact_person LIKE ? OR cm.email LIKE ? OR cm.phone LIKE ? OR cm.gst_no LIKE ? OR cm.nda LIKE ? OR cm.initial LIKE ?)";
    $like = "%$q%";
    $params = array_fill(0, 8, $like);
    $types = str_repeat('s', count($params));
}
if (($fstatus === 'Active' || $fstatus === 'Inactive') && !$isPassive) {
    $cond[] = "cm.status=?";
    $params[] = $fstatus;
    $types .= 's';
}

// Add conditions if we have any (and not filtering for passive clients)
if ($cond && !$isPassive) {
    $sql .= " AND " . implode(" AND ", $cond);
}

// Add GROUP BY for passive clients to avoid duplicates if a client has multiple jobs
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
        if (!$clients) {
            $error = "Query failed: " . $mysqli->error;
        }
    }
} catch (Exception $e) {
    $error = "Database error: " . $e->getMessage();
}

require 'header.php';
?>

<div class="container-fluid py-4" style="margin-top: 60px;">

<?php if (!empty($_GET['msg'])): ?>
<div class="alert alert-<?= h($_GET['type'] ?? 'info') ?> alert-dismissible fade show" role="alert">
    <?= h($_GET['msg']) ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>

<?php if($mode==='add'||$mode==='edit'): ?>
<!-- ADD/EDIT FORM -->
<div class="d-flex justify-content-between align-items-center mb-3">
    <h5><?= $mode==='edit'?'Edit Client':'Add Client' ?></h5>
    <a href="client_master.php" class="btn btn-sm btn-secondary">Back</a>
</div>
<div class="card mb-3 mx-auto" style="max-width:1200px;">
    <div class="card-body">
        <form method="post" autocomplete="off" id="clientForm">
            <input type="hidden" name="action" value="<?= $mode==='edit'?'edit':'add' ?>">
            <?php if($mode==='edit'): ?><input type="hidden" name="client_id" value="<?= (int)($editClient['client_id'] ?? 0) ?>"><?php endif; ?>

            <div class="row g-3">
                <div class="col-md-6"> 
                    <label class="form-label small">Client Name <span class="text-danger">*</span></label>
                    <input type="text" name="client_name" class="form-control form-control-sm" required value="<?= h($editClient['client_name'] ?? '') ?>">
                </div>
                <div class="col-md-6">
                    <label class="form-label small">Initial</label>
                    <input type="text" name="initial" class="form-control form-control-sm" value="<?= h($editClient['initial'] ?? '') ?>">
                </div>
                <div class="col-md-6">
                    <label class="form-label small">Company</label>
                    <input type="text" name="company_name" class="form-control form-control-sm" value="<?= h($editClient['company_name'] ?? '') ?>">
                </div>

                <div class="col-md-3">
                    <label class="form-label small">Contact Person</label>
                    <input type="text" name="contact_person" class="form-control form-control-sm" value="<?= h($editClient['contact_person'] ?? '') ?>">
                </div>
                <div class="col-md-3">
                    <label class="form-label small">Email Payment</label>
                    <input type="email" name="email" class="form-control form-control-sm" value="<?= h($editClient['email'] ?? '') ?>">
                </div>
                <div class="col-md-3">
                    <label class="form-label small">Phone</label>
                    <input type="text" name="phone" class="form-control form-control-sm" value="<?= h($editClient['phone'] ?? '') ?>">
                </div>
                <div class="col-md-3">
                    <label class="form-label small">Additional Mobile</label>
                    <input type="text" name="additional_mobile" class="form-control form-control-sm" value="<?= h($editClient['additional_mobile'] ?? '') ?>">
                </div>

                <div class="col-md-6">
                    <label class="form-label small">Country</label>
                    <input type="text" name="country" id="country" class="form-control form-control-sm" value="<?= h($editClient['country'] ?? 'India') ?>" onchange="toggleTaxFields()">
                </div>

                <div class="col-md-6">
                    <label class="form-label small">Address</label>
                    <textarea name="address" id="address" class="form-control form-control-sm" rows="2" onkeyup="toggleTaxFields()"><?= h($editClient['address'] ?? '') ?></textarea>
                </div>

                <div class="col-md-3">
                    <label class="form-label small">Email Files</label>
                    <input type="email" name="general_email" class="form-control form-control-sm" value="<?= h($editClient['general_email'] ?? '') ?>">
                </div>
                <div class="col-md-3">
                    <label class="form-label small">Email Invoice</label>
                    <input type="email" name="invoice_email" class="form-control form-control-sm" value="<?= h($editClient['invoice_email'] ?? '') ?>">
                </div>
                <div class="col-md-3">
                    <label class="form-label small">Additional Email</label>
                    <input type="email" name="production_email" class="form-control form-control-sm" value="<?= h($editClient['production_email'] ?? '') ?>">
                </div>
                <div class="col-md-3">
                    <label class="form-label small">Additional Address</label>
                    <textarea name="additional_address" class="form-control form-control-sm" rows="2"><?= h($editClient['additional_address'] ?? '') ?></textarea>
                </div>

                <div class="col-md-4">
                    <label class="form-label small">GST / Tax No</label>
                    <input type="text" name="gst_no" id="gst_no" class="form-control form-control-sm" value="<?= h($editClient['gst_no'] ?? '') ?>">
                </div>
                <div class="col-md-4">
                    <label class="form-label small">Preferred Communication <span class="text-danger">*</span></label>
                    <select name="preferred_communication" class="form-select form-select-sm">
                        <option value="">Select</option>
                        <option value="Email" <?= ($editClient['preferred_communication'] ?? '')==='Email'?'selected':'' ?>>Email</option>
                        <option value="Phone" <?= ($editClient['preferred_communication'] ?? '')==='Phone'?'selected':'' ?>>Phone</option>
                        <option value="Portal" <?= ($editClient['preferred_communication'] ?? '')==='Portal'?'selected':'' ?>>Portal</option>
                    </select>
                </div>
                <div class="col-md-4">
                    <label class="form-label small">Preferred Language</label>
                    <select name="preferred_language" class="form-select form-select-sm">
                        <option value="">Select Language</option>
                        <?php foreach($languages as $lang): ?>
                            <option value="<?= h($lang) ?>" <?= ($editClient['preferred_language'] ?? '')===$lang?'selected':'' ?>><?= h($lang) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <h6 class="mt-4 border-bottom pb-2">Billing Rates & Tax Information</h6>

                <div class="row g-2">
                    <div class="col-md-2">
                        <label class="form-label small">Currency</label>
                        <select name="currency" id="currency" class="form-select form-select-sm" required>
                            <option value="">Select Currency</option>
                            <?php 
                            // Auto-select currency logic
                            $defaultCurrency = 'USD';
                            if (isset($editClient)) {
                                $defaultCurrency = $editClient['currency'] ?? 'USD';
                            } elseif (!isset($editClient) && ($editClient['country'] ?? 'India') === 'India') {
                                $defaultCurrency = 'INR';
                            }
                            foreach($currencies as $code => $name): 
                                $selected = ($defaultCurrency === $code) ? 'selected' : '';
                            ?>
                                <option value="<?= h($code) ?>" <?= $selected ?>>
                                    <?= h($code) ?> - <?= h($name) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="col-md-1">
                        <label class="form-label small">Word Rate</label>
                        <input type="number" name="word_rate" class="form-control form-control-sm" step="0.0001" value="<?= h($editClient['word_rate'] ?? 0.0000) ?>">
                    </div>

                    <div class="col-md-1">
                        <label class="form-label small">Page Rate</label>
                        <input type="number" name="page_rate" class="form-control form-control-sm" step="0.0001" value="<?= h($editClient['page_rate'] ?? 0.0000) ?>">
                    </div>
                    
                    <div class="col-md-1">
                        <label class="form-label small">Math Rate</label>
                        <input type="number" name="math_rate" class="form-control form-control-sm" step="0.0001" value="<?= h($editClient['math_rate'] ?? 0.0000) ?>">
                    </div>

                    <div class="col-md-1">
                        <label class="form-label small">Ref Rate</label>
                        <input type="number" name="ref_rate" class="form-control form-control-sm" step="0.0001" value="<?= h($editClient['ref_rate'] ?? 0.0000) ?>">
                    </div>

                    <div class="col-md-1">
                        <label class="form-label small">GST %</label>
                        <input type="number" name="gst_per" id="gst_per" class="form-control form-control-sm" step="any" value="<?= h($editClient['gst_per'] ?? 0) ?>">
                    </div>

                    <div class="col-md-1">
                        <label class="form-label small">CGST %</label>
                        <input type="number" name="cgst" id="cgst" class="form-control form-control-sm" step="0.01" min="0" max="100" value="<?= h($editClient['cgst'] ?? 0) ?>">
                    </div>

                    <div class="col-md-1">
                        <label class="form-label small">IGST %</label>
                        <input type="number" name="igst" id="igst" class="form-control form-control-sm" step="0.01" min="0" max="100" value="<?= h($editClient['igst'] ?? 0) ?>">
                    </div>

                    <!--div class="col-md-2">
                        <label class="form-label small">PayPal Email</label>
                        <input type="email" name="paypal_email" id="paypal_email" class="form-control form-control-sm" value="<?= h($editClient['paypal_email'] ?? '') ?>">
                    </div-->

                    <div class="col-md-1">
                        <label class="form-label small">PayPal %</label>
                        <input type="number" name="paypal_percentage" id="paypal_percentage" class="form-control form-control-sm" step="0.01" min="0" max="100" value="<?= h($editClient['paypal_percentage'] ?? 0) ?>">
                    </div>
                    
                    <!--div class="col-md-2">
                        <label class="form-label small">NDA</label>
                        <input type="text" name="nda" class="form-control form-control-sm" value="<?= h($editClient['nda'] ?? '') ?>">
                    </div>
                    
                    <div class="col-md-2">
                        <label class="form-label small">Date</label>
                        <input type="date" name="date" class="form-control form-control-sm" value="<?= h($editClient['date'] ?? date('Y-m-d')) ?>">
                    </div-->
                </div>
                
                <div class="d-flex justify-content-end gap-2 mt-4">
                    <button type="submit" class="btn btn-sm btn-primary"><?= $mode==='edit'?'Update':'Save' ?></button>
                    <a href="client_master.php" class="btn btn-sm btn-outline-secondary">Cancel</a>
                </div>
            </div>
        </form>
    </div>
</div>

<?php else: ?>
<!-- LIST VIEW -->
<div class="d-flex justify-content-between align-items-center mb-2">
    <h5>Client Master <?= $isPassive ? '<span class="badge bg-warning">Passive Clients</span>' : '' ?></h5>
    <!--div>
        <a href="client_master.php?mode=add" class="btn btn-sm btn-success"><i class="fa fa-plus"></i> Add</a>
        <button type="button" class="btn btn-sm btn-primary ms-2" data-bs-toggle="modal" data-bs-target="#importModal">
            <i class="bi bi-file-earmark-excel"></i> Import Excel
        </button>
    </div-->
</div>

<!-- Search and Filter Section -->
<form method="get" class="row gy-2 gx-2 mb-3 align-items-center">
    <div class="col-auto">
        <input type="text" name="q" value="<?= h($q) ?>" placeholder="Search client / company / contact / phone / gst / nda / initial" autocomplete="off" class="form-control form-control-sm" <?= $isPassive ? 'disabled' : '' ?>>
    </div>
    <div class="col-auto">
        <select name="status" class="form-select form-select-sm" id="statusFilter">
            <option value="">All Status</option>
            <option value="Active" <?= $fstatus==='Active'?'selected':'' ?>>Active</option>
            <option value="Inactive" <?= $fstatus==='Inactive'?'selected':'' ?>>Inactive</option>
            <option value="Passive" <?= $fstatus==='Passive'?'selected':'' ?>>Passive (No Jobs)</option>
        </select>
    </div>
    <div class="col-auto">
        <select name="sort" class="form-select form-select-sm">
            <?php foreach($sortable as $s): ?>
                <option value="<?= $s ?>" <?= $sort===$s?'selected':'' ?>><?= ucwords(str_replace('_',' ',$s)) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="col-auto">
        <select name="dir" class="form-select form-select-sm">
            <option value="asc" <?= $dir==='asc'?'selected':'' ?>>Asc</option>
            <option value="desc" <?= $dir==='desc'?'selected':'' ?>>Desc</option>
        </select>
    </div>
    <div class="col-auto">
        <button type="submit" class="btn btn-primary btn-sm">Go</button>
        <a href="client_master.php" class="btn btn-secondary btn-sm">Clear</a>
    </div>
    
    <!-- View Toggle Buttons -->
    <div class="col-auto border-start ps-2">
        <div class="btn-group btn-group-sm" role="group">
            <button type="button" class="btn btn-outline-primary active" onclick="toggleView('all')">All Info</button>
            <button type="button" class="btn btn-outline-primary" onclick="toggleView('basic')">Basic Info</button>
            <button type="button" class="btn btn-outline-primary" onclick="toggleView('payment')">Payment Info</button>
        </div>
    </div>
</form>

<?php if ($isPassive): ?>
<div class="alert alert-info alert-dismissible fade show mb-3" role="alert">
    <i class="bi bi-info-circle me-2"></i>
    Showing clients who have no jobs in the jobs table. These are "passive" clients who haven't had any work assigned yet.
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>

<div class="card">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0 small" id="clientTable">
                <thead class="table-light">
                    <tr>
                        <th>#</th>
                        <th>Initial</th>
                        <th>Client Name</th>
                        <th class="basic-col">Company</th>
                        <th class="basic-col">Contact</th>
                        <th class="basic-col" width="5%">Email Payment</th>
                        <th class="basic-col" width="5%">Email File</th>
                        <th class="basic-col" width="5%">Email Invoice</th>
                        <th>Phone</th>
                        <th>Country</th>
                        <th>GST</th>
                        <th class="payment-col">W Rate</th>
                        <th class="payment-col">P Rate</th>
                        <th class="payment-col">M Rate</th>
                        <th class="payment-col">R Rate</th>
                        <th class="payment-col">GST %</th>
                        <th class="payment-col">CGST %</th>
                        <th class="payment-col">IGST %</th>
                        <th class="basic-col">Currency</th>
                        <th class="payment-col">PayPal %</th>
                        <th class="basic-col">Pref. Comm.</th>
                        <th class="payment-col">NDA</th>
                        <th class="payment-col">Year</th>
                        <th class="basic-col">Status</th>
                        <th class="basic-col">Created</th>
                        <th class="text-end" style="width: 120px;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if($clients && $clients->num_rows):$i=0;
                        while($row = $clients->fetch_assoc()): $i=$i+1;?>
                    <tr>
                        <td><?= $i ?></td>
                        <td><strong><?= h($row['initial']) ?></strong></td>
                        <td><strong><?= h($row['client_name']) ?></strong></td>
                        <td class="basic-col"><?= h($row['company_name']) ?></td>
                        <td class="basic-col"><?= h($row['contact_person']) ?></td>
                        <td class="basic-col"><?= h($row['email']) ?></td>
                        <td class="basic-col"><?= h($row['email_files']) ?></td>
                        <td class="basic-col"><?= h($row['invoice_email']) ?></td>
                        
                       <td>
                            <small class="text-muted"><?= h($row['phone'])?> 
                            <?php if($row['additional_mobile']!='') { echo " , ";?> 
                                <?= h($row['additional_mobile']) ?> 
                            <?php } ?></small>
                        </td>
                        <td><?= h($row['country']) ?></td>
                        <td class="payment-col"><?= h($row['gst_no']) ?></td>
                        <td class="payment-col"><?= number_format((float)($row['word_rate'] ?? 0), 2) ?></td>
                        <td class="payment-col"><?= number_format((float)($row['page_rate'] ?? 0), 2) ?></td>
                        <td class="payment-col"><?= number_format((float)($row['math_rate'] ?? 0), 2) ?></td>
                        <td class="payment-col"><?= number_format((float)($row['ref_rate'] ?? 0), 2) ?></td>
                        <td class="payment-col"><?= number_format((float)($row['gst_per'] ?? 0), 2) ?></td>
                        <td class="payment-col"><?= number_format((float)($row['cgst'] ?? 0), 2) ?></td>
                        <td class="payment-col"><?= number_format((float)($row['igst'] ?? 0), 2) ?></td>
                        <td class="basic-col"><?= h($row['currency'] ?? 'USD') ?></td>
                        <td class="payment-col"><?= number_format((float)($row['paypal_percentage'] ?? 0), 2) ?></td>
                        <td class="basic-col"><?= h($row['preferred_communication']) ?></td>
                        <td class="payment-col"><?= h($row['nda'])?></td>
                        <td class="payment-col"><?php echo !empty($row['date']) ? date("Y", strtotime($row['date'])) : '—'; ?></td>
                        <td class="basic-col">
                            <form method="post" style="display:inline;" onsubmit="return confirm('Change status of <?= h($row['client_name']) ?> from <?= $row['status'] ?> to <?= $row['status']==='Active'?'Inactive':'Active' ?>?')">
                                <input type="hidden" name="action" value="toggle">
                                <input type="hidden" name="client_id" value="<?= (int)$row['client_id'] ?>">
                                <button type="submit" class="btn btn-sm <?= $row['status']==='Active'?'btn-success':'btn-danger' ?>"><?= h($row['status']) ?></button>
                            </form>
                        </td>
                        <td class="basic-col"><?= h(formatDate($row['created_date'])) ?></td>
                        <td class="text-end">
                            <div class="d-inline-flex align-items-center gap-1">
                                <a class="btn btn-sm btn-warning" href="client_master.php?mode=edit&id=<?= (int)$row['client_id'] ?>" title="Edit Client">
                                    <i class="fa fa-edit"></i>
                                </a>
                                
                                <form method="post" style="display:inline;" onsubmit="return confirm('Delete client <?= h($row['client_name']) ?>?')">
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="client_id" value="<?= (int)$row['client_id'] ?>">
                                    <button class="btn btn-sm btn-danger" title="Delete Client">
                                        <i class="fa fa-trash"></i>
                                    </button>
                                </form>
                            </div>
                        </td>
                    </tr>
                    <?php endwhile; else: ?>
                    <tr>
                        <td colspan="24" class="text-center py-4">
                            <?php if ($error): ?>
                                Error loading clients: <?= h($error) ?>
                            <?php else: ?>
                                No clients found.
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        <div class="card-footer text-muted text-center small py-1">
            <?php if($clients && $clients->num_rows): ?>
                Showing <?= $clients->num_rows ?> clients.
                <?php if ($isPassive): ?>
                    (Clients with no jobs)
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php endif; ?>

<!-- IMPORT MODAL -->
<div class="modal fade" id="importModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title"><i class="bi bi-file-earmark-excel me-2"></i>Import Excel File</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form action="" method="post" enctype="multipart/form-data">
                <input type="hidden" name="action" value="import_excel">
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label fw-bold">Select Excel File (.xlsx or .xls)</label>
                        <input type="file" name="excel_file" accept=".xls,.xlsx" class="form-control" required>
                    </div>
                    
                    <div class="alert alert-info">
                        <h6><i class="bi bi-info-circle me-2"></i>Excel Format Information:</h6>
                        <p class="mb-2">The Excel file should follow this column structure:</p>
                        <ul class="small mb-0">
                            <li>Column A: Month (for other purposes, not used in import)</li>
                            <li>Column B: Client Name (required)</li>
                            <li>Column C: Initial</li>
                            <li>Column D: Email</li>
                            <li>Column E: Contact</li>
                            <li>Column F: Designation</li>
                            <li>... and so on up to Column BO (75 columns total)</li>
                        </ul>
                        <p class="mt-2 mb-0"><strong>Note:</strong> Existing clients will be updated, new clients will be added.</p>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="submit" class="btn btn-primary">Upload & Import</button>
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                </div>
            </form>
        </div>
    </div>
</div>

</div>

<script>
// Function to toggle tax fields based on country and address
function toggleTaxFields() {
    const country = document.getElementById('country').value.toLowerCase();
    const address = document.getElementById('address').value.toLowerCase();
    
    const gstNo = document.getElementById('gst_no');
    const gstPer = document.getElementById('gst_per');
    const cgst = document.getElementById('cgst');
    const igst = document.getElementById('igst');
    const paypalEmail = document.getElementById('paypal_email');
    const paypalPercentage = document.getElementById('paypal_percentage');
    const currencySelect = document.getElementById('currency');
    
    // Reset all fields to editable first
    [gstNo, gstPer, cgst, igst, paypalEmail, paypalPercentage].forEach(field => {
        field.disabled = false;
        field.style.backgroundColor = '';
        field.style.color = '';
    });
    
    if (country === 'india' || country === 'ind') {
        // Auto-select INR currency for India
        if (currencySelect) {
            let foundINR = false;
            for (let i = 0; i < currencySelect.options.length; i++) {
                if (currencySelect.options[i].value === 'INR') {
                    currencySelect.selectedIndex = i;
                    foundINR = true;
                    break;
                }
            }
            // If INR not found, try to select it
            if (!foundINR && currencySelect.options.length > 0) {
                // Try to find a currency option that contains "INR"
                for (let i = 0; i < currencySelect.options.length; i++) {
                    if (currencySelect.options[i].text.includes('INR')) {
                        currencySelect.selectedIndex = i;
                        break;
                    }
                }
            }
        }
        
        // India logic
        if (address.includes('tamil') || address.includes('tamilnadu') || address.includes('tamil nadu')) {
            // Tamil Nadu: Enable CGST/IGST, disable PayPal
            paypalEmail.disabled = true;
            paypalPercentage.disabled = true;
            paypalEmail.style.backgroundColor = '#f8f9fa';
            paypalPercentage.style.backgroundColor = '#f8f9fa';
            paypalEmail.value = '';
            paypalPercentage.value = '0';
            
            // Enable GST fields
            gstNo.disabled = false;
            gstPer.disabled = false;
            cgst.disabled = false;
            igst.disabled = false;
        } else {
            // Other Indian states: Enable GST only, disable CGST/IGST
            cgst.disabled = true;
            igst.disabled = true;
            cgst.style.backgroundColor = '#f8f9fa';
            igst.style.backgroundColor = '#f8f9fa';
            cgst.value = '0';
            igst.value = '0';
            
            // Enable GST fields
            gstNo.disabled = false;
            gstPer.disabled = false;
            
            // PayPal can be enabled for other Indian states
            paypalEmail.disabled = false;
            paypalPercentage.disabled = false;
        }
    } else {
        // Non-India: Enable PayPal only, disable GST fields
        // Auto-select USD or other non-INR currency
        if (currencySelect && currencySelect.value === 'INR') {
            // Change to USD if currently INR
            let foundUSD = false;
            for (let i = 0; i < currencySelect.options.length; i++) {
                if (currencySelect.options[i].value === 'USD') {
                    currencySelect.selectedIndex = i;
                    foundUSD = true;
                    break;
                }
            }
            // If USD not found, select first non-INR option
            if (!foundUSD) {
                for (let i = 0; i < currencySelect.options.length; i++) {
                    if (currencySelect.options[i].value !== 'INR' && currencySelect.options[i].value !== '') {
                        currencySelect.selectedIndex = i;
                        break;
                    }
                }
            }
        }
        
        [gstNo, gstPer, cgst, igst].forEach(field => {
            field.disabled = true;
            field.style.backgroundColor = '#f8f9fa';
            field.style.color = '#6c757d';
        });
        
        gstNo.value = '';
        gstPer.value = '0';
        cgst.value = '0';
        igst.value = '0';
        
        // Enable PayPal
        paypalEmail.disabled = false;
        paypalPercentage.disabled = false;
    }
}

// Function to update currency based on country
function updateCurrencyBasedOnCountry() {
    const country = document.getElementById('country').value.toLowerCase();
    const currencySelect = document.getElementById('currency');
    
    if (country === 'india' || country === 'ind') {
        // Auto-select INR for India
        if (currencySelect) {
            for (let i = 0; i < currencySelect.options.length; i++) {
                if (currencySelect.options[i].value === 'INR') {
                    currencySelect.selectedIndex = i;
                    break;
                }
            }
        }
    } else {
        // Auto-select USD for non-India (if current selection is INR)
        if (currencySelect && currencySelect.value === 'INR') {
            for (let i = 0; i < currencySelect.options.length; i++) {
                if (currencySelect.options[i].value === 'USD') {
                    currencySelect.selectedIndex = i;
                    break;
                }
            }
        }
    }
    toggleTaxFields(); // Also update tax fields
}

// Initialize on page load and when country/address changes
document.addEventListener('DOMContentLoaded', function() {
    // Add event listeners
    const countryField = document.getElementById('country');
    const addressField = document.getElementById('address');
    
    if (countryField) {
        countryField.addEventListener('change', function() {
            updateCurrencyBasedOnCountry();
            toggleTaxFields();
        });
        countryField.addEventListener('keyup', function() {
            updateCurrencyBasedOnCountry();
            toggleTaxFields();
        });
    }
    
    if (addressField) {
        addressField.addEventListener('keyup', toggleTaxFields);
        addressField.addEventListener('change', toggleTaxFields);
    }
    
    // Initialize tax fields on page load
    setTimeout(function() {
        toggleTaxFields();
    }, 100);
});

// Toggle between different views (for list view)
function toggleView(view) {
    const basicCols = document.querySelectorAll('.basic-col');
    const paymentCols = document.querySelectorAll('.payment-col');
    const buttons = document.querySelectorAll('.btn-group .btn');
    
    // Update button states
    buttons.forEach(btn => {
        btn.classList.remove('active');
        if (btn.textContent.includes(view.charAt(0).toUpperCase() + view.slice(1))) {
            btn.classList.add('active');
        }
    });
    
    if (view === 'all') {
        // Show all columns
        basicCols.forEach(col => col.style.display = '');
        paymentCols.forEach(col => col.style.display = '');
    } else if (view === 'basic') {
        // Show only basic columns
        basicCols.forEach(col => col.style.display = '');
        paymentCols.forEach(col => col.style.display = 'none');
    } else if (view === 'payment') {
        // Show only payment columns
        basicCols.forEach(col => col.style.display = 'none');
        paymentCols.forEach(col => col.style.display = '');
    }
}

// Live search functionality
document.addEventListener('DOMContentLoaded', function() {
    const qInput = document.querySelector('input[name="q"]');
    const statusFilter = document.getElementById('statusFilter');
    
    // Disable search input when Passive is selected
    if (statusFilter && qInput) {
        statusFilter.addEventListener('change', function() {
            qInput.disabled = (this.value === 'Passive');
            if (this.value === 'Passive') {
                qInput.value = '';
            }
        });
        
        // Initialize based on current status
        qInput.disabled = (statusFilter.value === 'Passive');
    }
    
    if (qInput) {
        let timeout = null;
        qInput.addEventListener('keyup', function(e) {
            clearTimeout(timeout);
            timeout = setTimeout(() => {
                if (!this.disabled && (this.value.length >= 2 || this.value.length === 0)) {
                    this.form.submit();
                }
            }, 500);
        });
    }
    
    // Set default view to 'all' for list view
    if (document.getElementById('clientTable')) {
        toggleView('all');
    }
});
</script>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>