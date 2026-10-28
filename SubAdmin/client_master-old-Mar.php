<?php
require 'db.php';
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
if (session_status() === PHP_SESSION_NONE) { session_start(); }

// ---------- Helpers ----------
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
?>
<br><br><br>
<?php
// ---------- Handle POST actions ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    // ========== EXCEL IMPORT HANDLER ==========
    if ($action === 'import_excel') {
        ini_set('display_errors', 1);
        ini_set('display_startup_errors', 1);
        error_reporting(E_ALL);
        
        if (isset($_FILES['excel_file']) && $_FILES['excel_file']['error'] === 0) {
            
            // Path to Library
            $libPath = dirname(__DIR__) . '/simplexlsx-master/src/SimpleXLSX.php';
            
            if (!file_exists($libPath)) {
                // Fallback without src
                $libPath = dirname(__DIR__) . '/simplexlsx-master/SimpleXLSX.php';
            }

            if (!file_exists($libPath)) {
                redirect_with(['msg' => 'Library file not found at: ' . $libPath, 'type' => 'danger']);
            }
            
            require_once $libPath;
            
            // Check for Namespace or Global class
            $xlsx = null;
            if (class_exists('Shuchkin\SimpleXLSX')) {
                $xlsx = Shuchkin\SimpleXLSX::parse($_FILES['excel_file']['tmp_name']);
            } elseif (class_exists('SimpleXLSX')) {
                $xlsx = SimpleXLSX::parse($_FILES['excel_file']['tmp_name']);
            } else {
                redirect_with(['msg' => 'Class SimpleXLSX not found.', 'type' => 'danger']);
            }

            if ($xlsx) {
                $rows = $xlsx->rows();
                $count = 0;
                $errors = 0;
                $duplicates = 0;
                $skipped = 0;
                $error_messages = [];
                
                // Track processed client names to detect duplicates within the import file
                $imported_names = [];
                
                foreach ($rows as $k => $r) {
                    if ($k === 0) continue; // Skip Header
                    
                    // Map Excel Columns: Country(0), Initial(1), Client Name(2), Email_Files(3), Link(4)
                    $country      = $mysqli->real_escape_string($r[0] ?? 'India');
                    $initial      = $mysqli->real_escape_string($r[1] ?? '');
                    $client_name  = trim($mysqli->real_escape_string($r[2] ?? ''));
                    $email_files  = $mysqli->real_escape_string($r[3] ?? '');
                    $link         = $mysqli->real_escape_string($r[4] ?? '');
                    
                    // Skip if client name is empty
                    if (empty($client_name)) {
                        $skipped++;
                        continue;
                    }
                    
                    // Check for duplicate within the import file itself
                    if (in_array($client_name, $imported_names)) {
                        $duplicates++;
                        $error_messages[] = "Row " . ($k+1) . ": Duplicate client name in import file - '$client_name'";
                        continue;
                    }
                    
                    // Check if client already exists in database
                    $check_sql = "SELECT COUNT(*) as count FROM client_master WHERE client_name = '$client_name'";
                    $check_result = $mysqli->query($check_sql);
                    if ($check_result) {
                        $row_count = $check_result->fetch_assoc()['count'];
                        $check_result->free();
                        
                        if ($row_count > 0) {
                            $duplicates++;
                            $error_messages[] = "Row " . ($k+1) . ": Client '$client_name' already exists in database";
                            continue;
                        }
                    }
                    
                    // Defaults
                    $company      = $client_name; 
                    $contact      = "Admin";      
                    $phone        = "0000000000"; 
                    $address      = "Imported Address"; 
                    $pref         = "Email";
                    $status       = "Active";
                    
                    // Extract valid email
                    $primary_email = 'no_email_'.time().$k.'@placeholder.com';
                    if (!empty($email_files)) {
                        if (preg_match('/[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}/', $email_files, $matches)) {
                            $primary_email = $matches[0];
                        }
                    }
                    
                    $sql = "INSERT INTO client_master 
                            (client_name, company_name, contact_person, email, email_files, phone, address, country, initial, link, preferred_communication, status, created_date) 
                            VALUES 
                            ('$client_name', '$company', '$contact', '$primary_email', '$email_files', '$phone', '$address', '$country', '$initial', '$link', '$pref', '$status', NOW())";
                    
                    if ($mysqli->query($sql)) {
                        $count++;
                        $imported_names[] = $client_name; // Track successfully imported names
                    } else {
                        $errors++;
                        $error_messages[] = "Row " . ($k+1) . ": Database error - " . $mysqli->error;
                    }
                }
                
                // Prepare success/error message
                $message = "Import Complete. Added: $count, Errors: $errors, Duplicates Skipped: $duplicates, Empty Rows Skipped: $skipped";
                
                // Add error details if any
                if (!empty($error_messages) && ($errors > 0 || $duplicates > 0)) {
                    $message .= "<br><small>Details: " . implode("; ", array_slice($error_messages, 0, 5));
                    if (count($error_messages) > 5) {
                        $message .= " and " . (count($error_messages) - 5) . " more...";
                    }
                    $message .= "</small>";
                }
                
                redirect_with(['msg' => $message, 'type' => ($count > 0) ? 'success' : 'warning']);
            } else {
                $err = (method_exists('Shuchkin\SimpleXLSX', 'parseError')) ? Shuchkin\SimpleXLSX::parseError() : 'Unknown Error';
                redirect_with(['msg' => 'SimpleXLSX Parse Error: ' . $err, 'type' => 'danger']);
            }
        } else {
            redirect_with(['msg' => 'No file uploaded or file error.', 'type' => 'danger']);
        }
    }
    // ========== OTHER POST ACTIONS ==========
    $client_name = trim($_POST['client_name'] ?? '');

    // Basic validation
    if (($action === 'add' || $action === 'edit') && $client_name === '') {
        redirect_with(['msg'=>'Client Name is required.','type'=>'danger']);
    }

    // --- Common Rate Fields ---
    $word_rate = (float)($_POST['word_rate'] ?? 0);
    $page_rate = (float)($_POST['page_rate'] ?? 0);
    $math_rate = (float)($_POST['math_rate'] ?? 0);
    $ref_rate = (float)($_POST['ref_rate'] ?? 0);
    $gst_per = (float)($_POST['gst_per'] ?? 0);
    $cgst = (float)($_POST['cgst'] ?? 0);
    $igst = (float)($_POST['igst'] ?? 0);
    $currency = trim($_POST['currency'] ?? 'USD');
    $paypal_percentage = (float)($_POST['paypal_percentage'] ?? 0);

if ($action === 'add') {
    // 1. First check if client already exists
    $check_client = $mysqli->real_escape_string(trim($_POST['client_name'] ?? ''));
    $check_sql = "SELECT COUNT(*) as count FROM client_master WHERE client_name = '$check_client'";
    $check_result = $mysqli->query($check_sql);
    
    if ($check_result) {
        $row_count = $check_result->fetch_assoc()['count'];
        $check_result->free();
        
        if ($row_count > 0) {
            redirect_with(['msg' => "Client '$check_client' already exists in database.", 'type' => 'danger']);
        }
    }
    
    // 2. Sanitize/Format all input variables
    $client_name = $mysqli->real_escape_string(trim($_POST['client_name'] ?? ''));
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
    $currency = $mysqli->real_escape_string(trim($_POST['currency'] ?? 'INR'));
    $status = 'Active';

    // Numeric variables
    $word_rate = floatval($_POST['word_rate'] ?? 0);
    $page_rate = floatval($_POST['page_rate'] ?? 0);
    $math_rate = floatval($_POST['math_rate'] ?? 0);
    $ref_rate = floatval($_POST['ref_rate'] ?? 0);
    $gst_per = floatval($_POST['gst_per'] ?? 0);
    $cgst = floatval($_POST['cgst'] ?? 0);
    $igst = floatval($_POST['igst'] ?? 0);
    $paypal_percentage = floatval($_POST['paypal_percentage'] ?? 0);

    // Debug: Check if all required fields are present
    if (!$client_name || !$company || !$contact || !$email || !$phone || !$address || !$country || !$pref) {
        redirect_with(['msg'=>'All required fields must be filled.','type'=>'danger']);
    }

    // 3. Construct the final SQL query using interpolation
    $sql = "INSERT INTO client_master 
        (client_name, company_name, contact_person, email, general_email, invoice_email, production_email, phone, additional_mobile, address, additional_address, country, gst_no, cgst, igst, paypal_email, paypal_percentage, preferred_communication, preferred_language, created_date, status, word_rate, page_rate, math_rate, ref_rate, gst_per, currency) 
        VALUES (
            '$client_name', 
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
            '$currency'
        )";
    
    // 4. Execute the query using mysqli::query()
    if ($mysqli->query($sql)) {
        redirect_with(['msg'=>'Client added successfully!','type'=>'success']);
    } else {
        redirect_with(['msg'=>'Error adding client: ' . $mysqli->error,'type'=>'danger']);
    }
}
if ($action === 'edit') {
    $id = (int)($_POST['client_id'] ?? 0);
    if ($id > 0) {
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
      $gst  = trim($_POST['gst_no'] ?? '');
      $pref  = trim($_POST['preferred_communication'] ?? '');
      $language = trim($_POST['preferred_language'] ?? '');
      $paypal_email = trim($_POST['paypal_email'] ?? '');
      $ref_rate = (float)($_POST['ref_rate'] ?? 0);

      $stmt = $mysqli->prepare("UPDATE client_master SET
        client_name=?, company_name=?, contact_person=?, email=?, general_email=?, invoice_email=?, production_email=?,
        phone=?, additional_mobile=?, address=?, additional_address=?, country=?, gst_no=?, preferred_communication=?, preferred_language=?,
        word_rate=?, page_rate=?, math_rate=?, ref_rate=?, gst_per=?, cgst=?, igst=?, currency=?, paypal_email=?, paypal_percentage=?
        WHERE client_id=?");
      
      if (!$stmt) {
        redirect_with(['msg'=>'Database error: ' . $mysqli->error,'type'=>'danger']);
      }
      
      $stmt->bind_param("sssssssssssssssddddddssdii",
        $client_name, $company, $contact, $email, $general_email, $invoice_email, $production_email,
        $phone, $additional_mobile, $address, $additional_address, $country, $gst, $pref, $language,
        $word_rate, $page_rate, $math_rate, $ref_rate, $gst_per, $cgst, $igst, $currency, $paypal_email, $paypal_percentage, $id);
      
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

// ---------- View / Edit ----------
$mode = $_GET['mode'] ?? 'view';
$editClient = null;
if ($mode === 'edit') {
  $id = (int)($_GET['id'] ?? 0);
  if ($id > 0) {
    $stmt = $mysqli->prepare("SELECT client_id, client_name, company_name, contact_person, email, general_email, invoice_email, production_email, phone, additional_mobile, address, additional_address, country, gst_no, preferred_communication, preferred_language, status, created_date, word_rate, page_rate, math_rate, ref_rate, gst_per, cgst, igst, currency, paypal_email, paypal_percentage FROM client_master WHERE client_id=?");
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

// ---------- Filters & Sorting ----------
$q    = trim($_GET['q'] ?? '');
$fstatus = $_GET['status'] ?? '';
$sort  = $_GET['sort'] ?? 'created_date';
$dir  = strtolower($_GET['dir'] ?? 'desc');
$sortable = ['client_id','client_name','company_name','email','phone','status','created_date', 'word_rate', 'page_rate', 'math_rate', 'ref_rate', 'gst_per'];
if (!in_array($sort, $sortable, true)) $sort = 'created_date';
$dir = ($dir === 'asc') ? 'asc' : 'desc';

// Build query
$sql = "SELECT client_id, client_name, company_name, contact_person, email, general_email, invoice_email, production_email, phone, additional_mobile, address, additional_address, country, gst_no, preferred_communication, preferred_language, status, created_date, word_rate, page_rate, math_rate, ref_rate, gst_per, cgst, igst, currency, paypal_email, paypal_percentage,nda,date
     FROM client_master";
$cond = []; $params = []; $types = '';
if ($q !== '') {
  $cond[] = "(client_name LIKE ? OR company_name LIKE ? OR contact_person LIKE ? OR email LIKE ? OR general_email LIKE ? OR invoice_email LIKE ? OR production_email LIKE ? OR phone LIKE ? OR additional_mobile LIKE ? OR address LIKE ? OR additional_address LIKE ? OR country LIKE ? OR gst_no LIKE ? OR preferred_communication LIKE ? OR preferred_language LIKE ? OR paypal_email LIKE ?)";
  $like = "%$q%";
  $params = array_fill(0, 16, $like);
  $types = str_repeat('s', count($params));
}
if ($fstatus === 'Active' || $fstatus === 'Inactive') {
  $cond[] = "status=?";
  $params[] = $fstatus;
  $types .= 's';
}

if ($cond) {
  $sql .= " WHERE " . implode(" AND ", $cond);
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

// If there's an error with the main query, try a simpler one
if (!$clients && !$error) {
  $error = "Unable to fetch clients";
  $clients = $mysqli->query("SELECT client_id, client_name FROM client_master LIMIT 10");
}
require 'header.php';
?>

<?php if (!empty($_GET['msg'])): ?>
<div class="alert alert-<?= h($_GET['type'] ?? 'info') ?> alert-dismissible fade show" role="alert">
  <?= h($_GET['msg']) ?>
  <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>

<?php if ($error): ?>
<div class="alert alert-danger alert-dismissible fade show" role="alert">
  Database Error: <?= h($error) ?>
  <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>

<?php if($mode==='add'||$mode==='edit'): ?>
<div class="d-flex justify-content-between align-items-center mb-3">
  <h5><?= $mode==='edit'?'Edit Client':'Add Client' ?></h5>
  <a href="client_master.php" class="btn btn-sm btn-secondary">Back</a>
</div>
<div class="card mb-3 mx-auto" style="max-width:1200px;">
  <div class="card-body">
    <form method="post" autocomplete="off">
      <input type="hidden" name="action" value="<?= $mode==='edit'?'edit':'add' ?>">
      <?php if($mode==='edit'): ?><input type="hidden" name="client_id" value="<?= (int)($editClient['client_id'] ?? 0) ?>"><?php endif; ?>

      <div class="row g-3">
        <div class="col-md-6"> <label class="form-label small">Client ID <span class="text-danger">*</span></label>
          <input type="text" name="client_name" class="form-control form-control-sm" required value="<?= h($editClient['client_name'] ?? '') ?>">
        </div>
        <div class="col-md-6">
          <label class="form-label small">Client Name</label>
          <input type="text" name="company_name" class="form-control form-control-sm" value="<?= h($editClient['company_name'] ?? '') ?>">
        </div>

        <div class="col-md-3">
          <label class="form-label small">Contact Person <span class="text-danger">*</span></label>
          <input type="text" name="contact_person" class="form-control form-control-sm" required value="<?= h($editClient['contact_person'] ?? '') ?>">
        </div>
        <div class="col-md-3">
          <label class="form-label small">Primary Email <span class="text-danger">*</span></label>
          <input type="email" name="email" class="form-control form-control-sm" required value="<?= h($editClient['email'] ?? '') ?>">
        </div>
        <div class="col-md-3">
          <label class="form-label small">Phone <span class="text-danger">*</span></label>
          <input type="text" name="phone" class="form-control form-control-sm" required value="<?= h($editClient['phone'] ?? '') ?>">
        </div>
        <div class="col-md-3">
          <label class="form-label small">Additional Mobile</label>
          <input type="text" name="additional_mobile" class="form-control form-control-sm" value="<?= h($editClient['additional_mobile'] ?? '') ?>">
        </div>

        <div class="col-md-3">
          <label class="form-label small">Country <span class="text-danger">*</span></label>
          <input type="text" name="country" class="form-control form-control-sm" required value="<?= h($editClient['country'] ?? 'India') ?>">
        </div>

        <div class="col-md-3">
          <label class="form-label small">General / Enquiry Email</label>
          <input type="email" name="general_email" class="form-control form-control-sm" value="<?= h($editClient['general_email'] ?? '') ?>">
        </div>
        <div class="col-md-3">
          <label class="form-label small">Invoice Email</label>
          <input type="email" name="invoice_email" class="form-control form-control-sm" value="<?= h($editClient['invoice_email'] ?? '') ?>">
        </div>
        <div class="col-md-3">
          <label class="form-label small">Production Email</label>
          <input type="email" name="production_email" class="form-control form-control-sm" value="<?= h($editClient['production_email'] ?? '') ?>">
        </div>

        <div class="col-md-4">
          <label class="form-label small">GST / Tax No</label>
          <input type="text" name="gst_no" class="form-control form-control-sm" value="<?= h($editClient['gst_no'] ?? '') ?>">
        </div>
        <div class="col-md-4">
          <label class="form-label small">Preferred Communication <span class="text-danger">*</span></label>
          <select name="preferred_communication" class="form-select form-select-sm" required>
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

        <div class="col-md-6">
          <label class="form-label small">Address <span class="text-danger">*</span></label>
          <textarea name="address" class="form-control form-control-sm" rows="2" required><?= h($editClient['address'] ?? '') ?></textarea>
        </div>
        
        <div class="col-md-6">
          <label class="form-label small">Additional Address</label>
          <textarea name="additional_address" class="form-control form-control-sm" rows="2"><?= h($editClient['additional_address'] ?? '') ?></textarea>
        </div>

   <h6 class="mt-4 border-bottom pb-2">Billing Rates & Tax Information</h6>

<div class="row g-2">
   <div class="col-md-2">
    <label class="form-label small">Currency</label>
    <select name="currency" class="form-select form-select-sm">
      <option value="USD" <?= ($editClient['currency'] ?? 'USD')==='USD'?'selected':'' ?>>USD</option>
      <option value="INR" <?= ($editClient['currency'] ?? 'USD')==='INR'?'selected':'' ?>>INR</option>
      <option value="EUR" <?= ($editClient['currency'] ?? 'USD')==='EUR'?'selected':'' ?>>EUR</option>
    
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
    <input type="number" name="gst_per" class="form-control form-control-sm" step="any" value="<?= h($editClient['gst_per'] ?? 0) ?>">
  </div>

  <div class="col-md-1">
    <label class="form-label small">CGST %</label>
    <input type="number" name="cgst" class="form-control form-control-sm" step="0.01" min="0" max="100" value="<?= h($editClient['cgst'] ?? 0) ?>">
  </div>

  <div class="col-md-1">
    <label class="form-label small">IGST %</label>
    <input type="number" name="igst" class="form-control form-control-sm" step="0.01" min="0" max="100" value="<?= h($editClient['igst'] ?? 0) ?>">
  </div>

  <div class="col-md-1">
    <label class="form-label small">PayPal %</label>
    <input type="number" name="paypal_percentage" class="form-control form-control-sm" step="0.01" min="0" max="100" value="<?= h($editClient['paypal_percentage'] ?? 0) ?>">
  </div>

</div>
      <div class="d-flex justify-content-end gap-2 mt-4">
        <button type="submit" class="btn btn-sm" style="background-color:brown; color:white;"><?= $mode==='edit'?'Update':'Save' ?></button>
        <a href="client_master.php" class="btn btn-sm btn-outline-secondary">Cancel</a>
      </div>
    </form>
  </div>
</div>



<?php else: ?>
<div class="d-flex justify-content-between align-items-center mb-2">
  <h5>Client Master</h5>
  <!--a href="client_master.php?mode=add" class="btn btn-sm btn-success"><i class="fa fa-plus"></i> Add</a-->
</div>

<form method="get" class="row gy-2 gx-2 mb-3 align-items-center">
  <div class="col-auto">
    <input type="text" name="q" value="<?= h($q) ?>" placeholder="Search client / company / contact / phone / gst" autocomplete="off" class="form-control form-control-sm">
  </div>
  <div class="col-auto">
    <select name="status" class="form-select form-select-sm">
      <option value="">All</option>
      <option value="Active" <?= $fstatus==='Active'?'selected':'' ?>>Active</option>
      <option value="Inactive" <?= $fstatus==='Inactive'?'selected':'' ?>>Inactive</option>
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
  <div class="col-auto">
   <!--button type="button" class="btn btn-success btn-sm d-flex align-items-center" 
                data-bs-toggle="modal" data-bs-target="#importModal">
          <i class="bi bi-file-earmark-excel me-1"></i> Import Excel
        </button-->
         <button type="button" class="btn btn-sm btn-info text-white" data-bs-toggle="modal" data-bs-target="#importModal">
        <i class="fa fa-file-excel"></i> Import Excel
      </button>

        </div>
</form>
<!--Import Button-->  
        <style>
    body {
        background-color: #f8f9fa;
    }
    .import-btn {
        background-color: #0d6efd;
        color: white;
        border-radius: 8px;
        padding: 10px 20px;
        font-weight: 500;
        transition: 0.3s;
    }
    .import-btn:hover {
        background-color: #0b5ed7;
    }
    .modal-header {
        background-color: #0d6efd;
        color: white;
    }
</style>
<!-- IMPORT EXCEL MODAL -->
<div class="modal fade" id="importModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header bg-info text-white">
        <h5 class="modal-title"><i class="fa fa-file-excel"></i> Import Clients</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <form method="post" enctype="multipart/form-data">
            <input type="hidden" name="action" value="import_excel">
            <div class="mb-3">
                <label class="form-label">Select Excel File (.xlsx)</label>
                <input type="file" name="excel_file" class="form-control" accept=".xlsx" required>
            </div>
            <div class="d-flex justify-content-end">
                <button type="button" class="btn btn-secondary me-2" data-bs-dismiss="modal">Close</button>
                <button type="submit" class="btn btn-info text-white">Import</button>
            </div>
        </form>
      </div>
    </div>
  </div>
</div>
<div class="card">
  <div class="card-body p-0">
    <div class="table-responsive">
      <table class="table table-hover align-middle mb-0 small">
        <thead class="table-light">
          <tr>
            <th>#</th>
            <th>Client ID/ Name</th>
            <th>Contact</th>
            <th>Email/Phone</th>
            <!--th>Additional Mobile</th-->
            <th>Country</th>
            <th>GST</th>
            <th>W Rate</th>
            <th>P Rate</th>
            <th>M Rate</th>
            <th>R Rate</th>
            <th>GST %</th>
            <th>CGST %</th>
            <th>IGST %</th>
            <th>Currency</th>
            <th>PayPal %</th>
            <th>Pref. Comm.</th>
            <th>NDA</th>
            <th>Year</th>
            <th>Status</th>
            <th>Created</th>
            <th class="text-end" style="width: 150px;">Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php if($clients && $clients->num_rows):$i=0;
            while($row = $clients->fetch_assoc()): $i=$i+1;?>
          <tr>
            <td><?= $i ?></td>
            <td>
              <strong><?= h($row['client_name']) ?></strong><br>
              <small class="text-muted"><?= h($row['company_name']) ?></small>
            </td>
            <td><?= h($row['contact_person']) ?></td>
            <td>
              <?= h($row['email']) ?><br>
              <small class="text-muted"><?= h($row['phone'])?> <?php if($row['additional_mobile']!='') { echo " , ";?> 
            <?= h($row['additional_mobile']) ?> <?php } ?></small></td>
            <td><?= h($row['country']) ?></td>
            <td><?= h($row['gst_no']) ?></td>
            <td><?= number_format((float)($row['word_rate'] ?? 0), 2) ?></td>
            <td><?= number_format((float)($row['page_rate'] ?? 0), 2) ?></td>
            <td><?= number_format((float)($row['math_rate'] ?? 0), 2) ?></td>
            <td><?= number_format((float)($row['ref_rate'] ?? 0), 2) ?></td>
            <td><?= number_format((float)($row['gst_per'] ?? 0), 2) ?></td>
            <td><?= number_format((float)($row['cgst'] ?? 0), 2) ?></td>
            <td><?= number_format((float)($row['igst'] ?? 0), 2) ?></td>
            <td><?= h($row['currency'] ?? 'USD') ?></td>
            <td><?= number_format((float)($row['paypal_percentage'] ?? 0), 2) ?></td>
            <td><?= h($row['preferred_communication']) ?></td>
            <td><?= h($row['nda'])?></td>
            <td><?php echo date("Y", strtotime($row['date']));?></td>
            <td>
              <form method="post" style="display:inline;" onsubmit="return confirm('Change status of <?= h($row['client_name']) ?> from <?= $row['status'] ?> to <?= $row['status']==='Active'?'Inactive':'Active' ?>?')">
                <input type="hidden" name="action" value="toggle">
                <input type="hidden" name="client_id" value="<?= (int)$row['client_id'] ?>">
                <button type="submit" class="btn btn-sm <?= $row['status']==='Active'?'btn-success':'btn-danger' ?>"><?= h($row['status']) ?></button>
              </form>
            </td>
            <td><?= h(formatDate($row['created_date'])) ?></td>
            <td class="text-end">
              <div class="d-inline-flex align-items-center client-action-group gap-1">
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
            <td colspan="19" class="text-center py-4">
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
      <?php endif; ?>
    </div>
  </div>
</div>

<?php endif; ?>

</div>
</div>

<script>
function applyFilter(){
    const q=document.querySelector('input[name="q"]').value.trim();
    const status=document.querySelector('select[name="status"]').value;
    const sort=document.querySelector('select[name="sort"]').value;
    const dir=document.querySelector('select[name="dir"]').value;
    const params=new URLSearchParams();
    if(q) params.set('q',q);
    if(status) params.set('status',status);
    if(sort) params.set('sort',sort);
    if(dir) params.set('dir',dir);
    window.location.href='client_master.php?'+params.toString();
}

// live search as typing
const qInput = document.querySelector('input[name="q"]');
const filterSelects = document.querySelectorAll('select[name="status"], select[name="sort"], select[name="dir"]');

// Add change listener to select filters
filterSelects.forEach(select => {
    select.addEventListener('change', () => {
        document.querySelector('form.row button[type="submit"]').click();
    });
});


if (qInput) {
    let timeout = null;
    qInput.addEventListener('keyup', function(e){
        clearTimeout(timeout);
        const self = this;
        timeout = setTimeout(function() {
             // Only filter if 2 or more characters, or cleared
            if (self.value.length >= 2 || self.value.length === 0) {
                 document.querySelector('form.row button[type="submit"]').click();
            }
        }, 500); // Debounce for 500ms
    });
}
</script>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>