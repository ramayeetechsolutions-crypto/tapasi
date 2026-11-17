<?php
require 'db.php';
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
if (session_status() === PHP_SESSION_NONE) { session_start(); }

// Include SimpleXLSX library for Excel import
$libPath = dirname(__DIR__) . '/simplexlsx-master/src/SimpleXLSX.php';
if (!file_exists($libPath)) $libPath = dirname(__DIR__) . '/simplexlsx-master/SimpleXLSX.php';
if (!file_exists($libPath)) $libPath = 'simplexlsx-master/src/SimpleXLSX.php';
if (!file_exists($libPath)) $libPath = 'simplexlsx-master/SimpleXLSX.php';

// Helper functions
function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function redirect_with($params =[]) {
    $base = 'client_master.php';
    if (!empty($params)) $base .= '?' . http_build_query($params);
    header("Location: $base");
    exit;
}

// ---------------------------------------------------------
// EXCEL IMPORT HANDLER
// ---------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'import_excel') {
    
    if (isset($_FILES['excel_file']) && $_FILES['excel_file']['error'] === 0) {
        
        if (!file_exists($libPath)) {
            redirect_with(['msg' => 'SimpleXLSX library not found. Please install it.', 'type' => 'danger']);
        }
        
        require_once $libPath;
        $xlsx = Shuchkin\SimpleXLSX::parse($_FILES['excel_file']['tmp_name']);

        if ($xlsx) {
            $rows = $xlsx->rows();
            $count_added = 0;
            $count_updated = 0;
            $count_skipped = 0;
            $errors =[];

            foreach ($rows as $k => $r) {
                if ($k === 0) continue; // Skip Header row

                try {
                    $country = trim($r[1] ?? 'India');         // B: Country
                    $currency = trim($r[2] ?? '');             // C: Currency
                    $payment = trim($r[3] ?? '');              // D: Payment
                    $frequency = trim($r[4] ?? '');            // E: Frequency
                    
                    // Handle Y0J (Year Only)
                    $date_raw = $r[5] ?? '';
                    $date = date('Y'); // Default to current year
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

                    $initial = trim($r[6] ?? '');              // G: Initial
                    $client_name = trim($r[7] ?? '');          // H: Client Name
                    $company_name = trim($r[8] ?? '');         // I: Invoice name
                    $address = trim($r[9] ?? '');              // J: Invoice address
                    $gst_no = trim($r[10] ?? '');              // K: GST
                    $invoice_email = trim($r[11] ?? '');       // L: Email_Invoice
                    $email_files = trim($r[12] ?? '');         // M: Email_Files
                    $email = trim($r[13] ?? '');               // N: Email_Payment Queries
                    $link = trim($r[14] ?? '');                // O: Link
                    $phone = trim($r[15] ?? '');               // P: Contact Number
                    $contact_person = trim($r[16] ?? '');      // Q: Contact Person
                    $gst_per = (float)($r[17] ?? 0);           // R: GST %
                    $cgst = (float)($r[18] ?? 0);              // S: CGST
                    $sgst = (float)($r[19] ?? 0);              // T: SGST
                    $payment_mode = trim($r[20] ?? '');        // U: Payment mode
                    $paypal_percentage = (float)($r[21] ?? 0); // V: PayPal %
                    $status = trim($r[22] ?? 'Active');        // W: Status

                    // Handle Created Date (Col X - index 23)
                    $created_date_raw = trim($r[23] ?? '');
                    $created_date = date('Y-m-d H:i:s');
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
                    
                    $check_stmt = $mysqli->prepare("SELECT client_id FROM client_master WHERE client_name = ?");
                    $check_stmt->bind_param("s", $client_name);
                    $check_stmt->execute();
                    $check_stmt->store_result();
                    $check = $mysqli->query("SELECT client_id FROM client_master WHERE client_name = '$client_name'");
                    
                    if ($check && $check->num_rows > 0) {
                        // Dynamically append created_date to update if provided
                        $created_date_sql = !empty($created_date_raw) ? ", created_date = '$created_date'" : "";

                        $sql = "UPDATE client_master SET 
                                initial = '$initial', company_name = '$company_name', contact_person = '$contact_person', 
                                email = '$email', email_files = '$email_files', invoice_email = '$invoice_email', 
                                phone = '$phone', address = '$address', country = '$country', gst_no = '$gst_no', 
                                cgst = $cgst, sgst = $sgst, paypal_percentage = $paypal_percentage, 
                                status = '$status', gst_per = $gst_per, currency = '$currency', 
                                date = '$date', payment = '$payment', frequency = '$frequency', link = '$link', payment_mode = '$payment_mode',
                                updated_date = NOW() $created_date_sql
                                WHERE client_name = '$client_name'";
                        
                        if ($mysqli->query($sql)) {
                            $count_updated++;
                        } else {
                            $errors[] = "Row " . ($k + 1) . ": Update error - " . $mysqli->error;
                            $count_skipped++;
                        }
                    } else {
                        $sql = "INSERT INTO client_master 
                                (client_name, initial, company_name, contact_person, email, email_files, 
                                invoice_email, phone, address, country, gst_no, 
                                cgst, sgst, paypal_percentage, status, gst_per, currency, 
                                date, payment, frequency, link, payment_mode, created_date, 
                                general_email, production_email, additional_mobile, additional_address, 
                                preferred_communication, preferred_language, paypal_email, nda, 
                                word_rate, page_rate, math_rate, ref_rate) 
                                VALUES 
                                ('$client_name', '$initial', '$company_name', '$contact_person', '$email', '$email_files', 
                                '$invoice_email', '$phone', '$address', '$country', '$gst_no', 
                                $cgst, $sgst, $paypal_percentage, '$status', $gst_per, '$currency', 
                                '$date', '$payment', '$frequency', '$link', '$payment_mode', '$created_date', 
                                '', '', '', '', 'Email', 'English', '', '', 0, 0, 0, 0)";
                        if ($mysqli->query($sql)) {
                            $count_added++;
                        } else {
                            $errors[] = "Row " . ($k + 1) . ": Insert error - " . $mysqli->error;
                            $count_skipped++;
                        }
                    }
                } catch (Exception $e) {
                    $errors[] = "Row " . ($k + 1) . ": Processing error - " . $e->getMessage();
                    $count_skipped++;
                }  
            }      
            
            $msg = "Process Complete.<br>";
            $msg .= "<strong>Imported:</strong> $count_added new clients.<br>";
            $msg .= "<strong>Updated:</strong> $count_updated existing clients.<br>";
            $msg .= "<strong>Skipped:</strong> $count_skipped rows.";
            
            if (!empty($errors)) {
                $msg .= "<br><small class='text-danger'>" . implode("<br>", array_slice($errors, 0, 5)) . "</small>";
                if (count($errors) > 5) {
                    $msg .= "<br>... and " . (count($errors) - 5) . " more errors.";
                }
            }
            
            $type = ($count_added > 0 || $count_updated > 0) ? 'success' : 'warning';
            redirect_with(['msg' => $msg, 'type' => $type]);
            
        } else {
            redirect_with(['msg' => 'Failed to parse Excel file. Please check the file format.', 'type' => 'danger']);
        }
    } else {
        redirect_with(['msg' => 'No file uploaded or file error.', 'type' => 'danger']);
    }
    exit;
}

// ---------------------------------------------------------
// MANUAL FORM SUBMISSIONS (Add/Edit/Delete/Toggle)
// ---------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $client_name = trim($_POST['client_name'] ?? '');

    if (($action === 'add' || $action === 'edit') && $client_name === '') {
        redirect_with(['msg'=>'Client Name is required.','type'=>'danger']);
    }

    if ($action === 'add') {
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
        $date = $mysqli->real_escape_string(trim($_POST['date'] ?? date('Y')));
        
        $payment = $mysqli->real_escape_string(trim($_POST['payment'] ?? ''));
        $frequency = $mysqli->real_escape_string(trim($_POST['frequency'] ?? ''));
        $link = $mysqli->real_escape_string(trim($_POST['link'] ?? ''));
        $payment_mode = $mysqli->real_escape_string(trim($_POST['payment_mode'] ?? ''));
        $status = 'Active';

        $word_rate = floatval($_POST['word_rate'] ?? 0);
        $page_rate = floatval($_POST['page_rate'] ?? 0);
        $math_rate = floatval($_POST['math_rate'] ?? 0);
        $ref_rate = floatval($_POST['ref_rate'] ?? 0);
        $gst_per = floatval($_POST['gst_per'] ?? 0);
        $cgst = floatval($_POST['cgst'] ?? 0);
        $sgst = floatval($_POST['sgst'] ?? 0);
        $paypal_percentage = floatval($_POST['paypal_percentage'] ?? 0);

        if (strtolower($country) === 'india' || strtolower($country) === 'ind') {
            $address_lower = strtolower($address);
            if (strpos($address_lower, 'tamil') !== false || strpos($address_lower, 'tamilnadu') !== false || strpos($address_lower, 'tamil nadu') !== false) {
                $paypal_percentage = 0;
                $paypal_email = '';
            } else {
                $cgst = 0;
                $sgst = 0;
            }
        } else {
            $gst = '';
            $gst_per = 0;
            $cgst = 0;
            $sgst = 0;
        }

        $sql = "INSERT INTO client_master 
            (client_name, initial, company_name, contact_person, email, general_email, invoice_email, production_email, phone, additional_mobile, address, additional_address, country, gst_no, cgst, sgst, paypal_email, paypal_percentage, preferred_communication, preferred_language, created_date, status, word_rate, page_rate, math_rate, ref_rate, gst_per, currency, date, payment, frequency, link, payment_mode) 
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
                $sgst, 
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
                '$date',
                '$payment',
                '$frequency',
                '$link',
                '$payment_mode'
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
            $date = trim($_POST['date'] ?? date('Y'));
            
            $payment = trim($_POST['payment'] ?? '');
            $frequency = trim($_POST['frequency'] ?? '');
            $link = trim($_POST['link'] ?? '');
            $payment_mode = trim($_POST['payment_mode'] ?? '');
            
            $currency = trim($_POST['currency'] ?? ($country === 'India' ? 'INR' : 'USD'));
            
            $word_rate = floatval($_POST['word_rate'] ?? 0);
            $page_rate = floatval($_POST['page_rate'] ?? 0);
            $math_rate = floatval($_POST['math_rate'] ?? 0);
            $gst_per = floatval($_POST['gst_per'] ?? 0);
            $cgst = floatval($_POST['cgst'] ?? 0);
            $sgst = floatval($_POST['sgst'] ?? 0);
            $paypal_percentage = floatval($_POST['paypal_percentage'] ?? 0);

            if (strtolower($country) === 'india' || strtolower($country) === 'ind') {
                $address_lower = strtolower($address);
                if (strpos($address_lower, 'tamil') !== false || strpos($address_lower, 'tamilnadu') !== false || strpos($address_lower, 'tamil nadu') !== false) {
                    $paypal_percentage = 0;
                    $paypal_email = '';
                } else {
                    $cgst = 0;
                    $sgst = 0;
                }
            } else {
                $gst = '';
                $gst_per = 0;
                $cgst = 0;
                $sgst = 0;
            }

            // Note: 'nda' has been removed from the update list below. 
            // Total variables: 31 parameters + 1 id = 32 params.
            $stmt = $mysqli->prepare("UPDATE client_master SET
                client_name=?, initial=?, company_name=?, contact_person=?, email=?, general_email=?, invoice_email=?, production_email=?,
                phone=?, additional_mobile=?, address=?, additional_address=?, country=?, gst_no=?, preferred_communication=?, preferred_language=?,
                word_rate=?, page_rate=?, math_rate=?, ref_rate=?, gst_per=?, cgst=?, sgst=?, currency=?, paypal_email=?, paypal_percentage=?, date=?,
                payment=?, frequency=?, link=?, payment_mode=?
                WHERE client_id=?");
            
            if (!$stmt) {
                redirect_with(['msg'=>'Database error: ' . $mysqli->error,'type'=>'danger']);
            }
            
            $stmt->bind_param(
                "ssssssssssssssssdddddddssdsssssi",
                $client_name,          // 1: s
                $initial,              // 2: s
                $company,              // 3: s
                $contact,              // 4: s
                $email,                // 5: s
                $general_email,        // 6: s
                $invoice_email,        // 7: s
                $production_email,     // 8: s
                $phone,                // 9: s
                $additional_mobile,    // 10: s
                $address,              // 11: s
                $additional_address,   // 12: s
                $country,              // 13: s
                $gst,                  // 14: s
                $pref,                 // 15: s
                $language,             // 16: s
                $word_rate,            // 17: d
                $page_rate,            // 18: d
                $math_rate,            // 19: d
                $ref_rate,             // 20: d
                $gst_per,              // 21: d
                $cgst,                 // 22: d
                $sgst,                 // 23: d
                $currency,             // 24: s
                $paypal_email,         // 25: s
                $paypal_percentage,    // 26: d
                $date,                 // 27: s
                $payment,              // 28: s
                $frequency,            // 29: s
                $link,                 // 30: s
                $payment_mode,         // 31: s
                $id                    // 32: i
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

    if ($action !== 'import_excel') {
        redirect_with();
    }
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

$languages =[];
$langResult = $mysqli->query("SELECT language_name FROM language_master WHERE status='Active' ORDER BY language_name");
if ($langResult) {
    while ($row = $langResult->fetch_assoc()) {
        $languages[] = $row['language_name'];
    }
} else {
    $languages =['English', 'Hindi', 'Spanish', 'French', 'German'];
}

$currencies =[];
$currencyResult = $mysqli->query("SELECT currency_code, currency_name FROM currency_master WHERE status='Active' ORDER BY currency_code");
if ($currencyResult) {
    while ($row = $currencyResult->fetch_assoc()) {
        $currencies[$row['currency_code']] = $row['currency_name'];
    }
} else {
    $currencies =['USD' => 'US Dollar', 'INR' => 'Indian Rupee', 'EUR' => 'Euro', 'GBP' => 'British Pound'];
}

// ---------------------------------------------------------
// FILTERS & SORTING
// ---------------------------------------------------------
$q = trim($_GET['q'] ?? '');
$fstatus = $_GET['status'] ?? '';
$sort = $_GET['sort'] ?? 'created_date';
$dir = strtolower($_GET['dir'] ?? 'desc');
$sortable =['client_id','client_name','company_name','email','phone','status','created_date', 'word_rate', 'page_rate', 'math_rate', 'ref_rate', 'gst_per', 'nda', 'date'];
if (!in_array($sort, $sortable, true)) $sort = 'created_date';
$dir = ($dir === 'asc') ? 'asc' : 'desc';

$sql = "SELECT cm.client_id, cm.client_name, cm.company_name, cm.contact_person, cm.initial, cm.email_files, cm.email, cm.general_email, cm.invoice_email, cm.production_email, cm.phone, cm.additional_mobile, cm.address, cm.additional_address, cm.country, cm.gst_no, cm.preferred_communication, cm.preferred_language, cm.status, cm.created_date, cm.word_rate, cm.page_rate, cm.math_rate, cm.ref_rate, cm.gst_per, cm.cgst, cm.sgst, cm.currency, cm.paypal_email, cm.paypal_percentage, cm.nda, cm.date, cm.payment, cm.frequency, cm.link, cm.payment_mode
     FROM client_master cm";

$isPassive = ($fstatus === 'Passive');
if ($isPassive) {
    $sql .= " LEFT JOIN jobs j ON cm.client_id = j.client_id 
              WHERE j.client_id IS NULL";
} else {
    $sql .= " WHERE 1=1";
}

$cond =[]; $params =[]; $types = '';
if ($q !== '' && !$isPassive) {
    // ADDED COUNTRY, CURRENCY, AND PAYMENT MODE SEARCH FIELDS HERE
    $cond[] = "(cm.client_name LIKE ? OR cm.company_name LIKE ? OR cm.contact_person LIKE ? OR cm.email LIKE ? OR cm.phone LIKE ? OR cm.gst_no LIKE ? OR cm.nda LIKE ? OR cm.initial LIKE ? OR cm.country LIKE ? OR cm.currency LIKE ? OR cm.payment_mode LIKE ?)";
    $like = "%$q%";
    // INCREASED ARRAY COUNT TO 11 TO MATCH THE NEW NUMBER OF LIKE CLAUSES
    $params = array_fill(0, 11, $like);
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
        if (!$clients) {
            $error = "Query failed: " . $mysqli->error;
        }
    }
} catch (Exception $e) {
    $error = "Database error: " . $e->getMessage();
}

require 'header.php';
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Client Master</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body {
            background-color: #f4f7f6;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }
        .main-content {
            padding: 25px;
            max-width: 100%;
        }
        /* Stylish Card */
        .card {
            border: none;
            box-shadow: 0 0.5rem 1rem rgba(0, 0, 0, 0.08);
            border-radius: 0.75rem;
        }
        .card-header-gradient {
            background: linear-gradient(135deg, #1e3c72 0%, #2a5298 100%);
            color: white;
            border-top-left-radius: 0.75rem !important;
            border-top-right-radius: 0.75rem !important;
        }
        /* Table Styling */
        .table-responsive {
            overflow-x: auto;
            border-radius: 0.5rem;
        }
        .table {
            margin-bottom: 0;
        }
        .table th {
            background-color: #2c3e50;
            color: white;
            border: none;
            font-size: 0.80rem;
            white-space: nowrap;
            padding: 12px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        .table td {
            vertical-align: middle;
            font-size: 0.78rem;
            white-space: nowrap;
            padding: 10px 12px;
        }
        .table-hover tbody tr:hover {
            background-color: #e8f4fd;
            transition: all 0.2s ease-in-out;
        }
        .btn-group-sm > .btn {
            padding: 0.25rem 0.5rem;
        }
        .status-badge {
            font-size: 0.75rem;
            padding: 0.35em 0.65em;
            border-radius: 50rem;
            font-weight: 600;
        }
        .form-label {
            font-weight: 600;
            color: #495057;
        }
        /* Fancy inputs */
        .form-control-sm, .form-select-sm {
            border-radius: 0.4rem;
            border: 1px solid #ced4da;
        }
        .form-control-sm:focus, .form-select-sm:focus {
            box-shadow: 0 0 0 0.2rem rgba(42, 82, 152, 0.25);
            border-color: #2a5298;
        }
    </style>
</head>
<body>
    
    <div class="container-fluid py-4 main-content">

    <?php if (!empty($_GET['msg'])): ?>
    <div class="alert alert-<?= h($_GET['type'] ?? 'info') ?> alert-dismissible fade show shadow-sm" role="alert">
        <?= $_GET['msg'] ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    <?php endif; ?>

    <?php if($mode==='add'||$mode==='edit'): ?>
    
    <!-- ADD/EDIT FORM -->
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h4 class="fw-bold text-dark"><i class="fas fa-user-edit text-primary me-2"></i><?= $mode==='edit'?'Edit Client':'Add New Client' ?></h4>
        <a href="client_master.php" class="btn btn-secondary btn-sm shadow-sm">
            <i class="fas fa-arrow-left me-1"></i> Back to List
        </a>
    </div>

    <div class="card mb-3 mx-auto">
        <div class="card-header card-header-gradient py-3">
            <h6 class="mb-0"><i class="fas fa-info-circle me-2"></i>Client Details</h6>
        </div>
      <div class="card-body p-4">
            <form method="post" autocomplete="off" id="clientForm">
                <input type="hidden" name="action" value="<?= $mode==='edit'?'edit':'add' ?>">
                <?php if($mode==='edit'): ?><input type="hidden" name="client_id" value="<?= (int)($editClient['client_id'] ?? 0) ?>"><?php endif; ?>

                <div class="row g-3">
                    
                    <!-- 1. Country -->
                    <div class="col-md-3">
                        <label class="form-label small">Country</label>
                        <input type="text" name="country" id="country" class="form-control form-control-sm" value="<?= h($editClient['country'] ?? 'India') ?>" onchange="toggleTaxFields()" onkeyup="toggleTaxFields()">
                    </div>

                    <!-- 2. Currency -->
                    <div class="col-md-3">
                        <label class="form-label small">Currency</label>
                        <select name="currency" id="currency" class="form-select form-select-sm" required>
                            <option value="">Select Currency</option>
                            <?php 
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

                    <!-- 3. Payment -->
                    <div class="col-md-3">
                        <label class="form-label small">Payment</label>
                        <select name="payment" class="form-select form-select-sm">
                            <option value="">Select Payment</option>
                            <?php 
                            $pay_opts =['Monthly', 'Bimonthly', 'Quarterly', 'Flexible','Biannual', 'Annually'];
                            foreach($pay_opts as $p): ?>
                                <option value="<?= h($p) ?>" <?= ($editClient['payment'] ?? '')===$p?'selected':'' ?>><?= h($p) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <!-- 4. Frequency -->
                    <div class="col-md-3">
                        <label class="form-label small">Frequency</label>
                        <select name="frequency" class="form-select form-select-sm">
                            <option value="">Select Frequency</option>
                            <?php 
                            $freq_opts =['Monthly', 'Bimonthly', 'Quarterly', 'Random', 'Biannual','Annually'];
                            foreach($freq_opts as $f): ?>
                                <option value="<?= h($f) ?>" <?= ($editClient['frequency'] ?? '')===$f?'selected':'' ?>><?= h($f) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <!-- 5. Y0J -->
                    <div class="col-md-2">
                        <label class="form-label small text-info fw-bold">Y0J (Year)</label>
                        <input type="number" name="date" class="form-control form-control-sm bg-info bg-opacity-10" min="1990" max="2100" placeholder="YYYY" value="<?= h($editClient['date'] ?? date('Y')) ?>">
                    </div>

                    <!-- 6. Initial -->
                    <div class="col-md-2">
                        <label class="form-label small">Initial</label>
                        <input type="text" name="initial" class="form-control form-control-sm" value="<?= h($editClient['initial'] ?? '') ?>">
                    </div>

                    <!-- 7. Client Name -->
                    <div class="col-md-4"> 
                        <label class="form-label small">Client Name <span class="text-danger">*</span></label>
                        <input type="text" name="client_name" class="form-control form-control-sm bg-light" required value="<?= h($editClient['client_name'] ?? '') ?>">
                    </div>

                    <!-- 8. Invoice name -->
                    <div class="col-md-4">
                        <label class="form-label small">Invoice name (Company)</label>
                        <input type="text" name="company_name" class="form-control form-control-sm" value="<?= h($editClient['company_name'] ?? '') ?>">
                    </div>

                    <!-- 9. Invoice address -->
                    <div class="col-md-6">
                        <label class="form-label small">Invoice address</label>
                        <textarea name="address" id="address" class="form-control form-control-sm" rows="2" onkeyup="toggleTaxFields()"><?= h($editClient['address'] ?? '') ?></textarea>
                    </div>

                    <!-- 9b. Additional address (Not in Excel but related) -->
                    <div class="col-md-6">
                        <label class="form-label small">Additional Address</label>
                        <textarea name="additional_address" class="form-control form-control-sm" rows="2"><?= h($editClient['additional_address'] ?? '') ?></textarea>
                    </div>

                    <!-- 10. GST -->
                    <div class="col-md-3">
                        <label class="form-label small">GST / Tax No</label>
                        <input type="text" name="gst_no" id="gst_no" class="form-control form-control-sm" value="<?= h($editClient['gst_no'] ?? '') ?>">
                    </div>

                    <!-- 11. Email_Invoice -->
                    <div class="col-md-3">
                        <label class="form-label small">Email_Invoice</label>
                        <input type="text" name="invoice_email" class="form-control form-control-sm" value="<?= h($editClient['invoice_email'] ?? '') ?>">
                    </div>

                    <!-- 12. Email_Files -->
                    <div class="col-md-3">
                        <label class="form-label small">Email_Files</label>
                        <input type="text" name="general_email" class="form-control form-control-sm" value="<?= h($editClient['general_email'] ?? '') ?>">
                    </div>

                    <!-- 13. Email_Payment Queries -->
                    <div class="col-md-3">
                        <label class="form-label small">Email_Payment Queries</label>
                        <input type="text" name="email" class="form-control form-control-sm" value="<?= h($editClient['email'] ?? '') ?>">
                    </div>

                    <!-- 14. Link -->
                    <div class="col-md-3">
                        <label class="form-label small">Link</label>
                        <input type="text" name="link" class="form-control form-control-sm" value="<?= h($editClient['link'] ?? '') ?>">
                    </div>

                    <!-- 15. Contact Number -->
                    <div class="col-md-3">
                        <label class="form-label small">Contact Number</label>
                        <input type="text" name="phone" class="form-control form-control-sm" value="<?= h($editClient['phone'] ?? '') ?>">
                    </div>

                    <!-- 15b. Additional Mobile (Not in Excel but related) -->
                    <div class="col-md-3">
                        <label class="form-label small">Additional Mobile</label>
                        <input type="text" name="additional_mobile" class="form-control form-control-sm" value="<?= h($editClient['additional_mobile'] ?? '') ?>">
                    </div>

                    <!-- 16. Contact Person -->
                    <div class="col-md-3">
                        <label class="form-label small">Contact Person</label>
                        <input type="text" name="contact_person" class="form-control form-control-sm" value="<?= h($editClient['contact_person'] ?? '') ?>">
                    </div>

                    <!-- 17. GST % -->
                    <div class="col-md-2">
                        <label class="form-label small">GST %</label>
                        <input type="number" name="gst_per" id="gst_per" class="form-control form-control-sm" step="any" value="<?= h($editClient['gst_per'] ?? 0) ?>">
                    </div>

                    <!-- 18. CGST -->
                    <div class="col-md-2">
                        <label class="form-label small">CGST %</label>
                        <input type="number" name="cgst" id="cgst" class="form-control form-control-sm" step="0.01" min="0" max="100" value="<?= h($editClient['cgst'] ?? 0) ?>">
                    </div>

                    <!-- 19. SGST -->
                    <div class="col-md-2">
                        <label class="form-label small">SGST %</label>
                        <input type="number" name="sgst" id="sgst" class="form-control form-control-sm" step="0.01" min="0" max="100" value="<?= h($editClient['sgst'] ?? 0) ?>">
                    </div>

                    <!-- 20. Payment mode -->
                    <div class="col-md-2">
                        <label class="form-label small">Payment mode</label>
                        <select name="payment_mode" class="form-select form-select-sm">
                            <option value="">Select Mode</option>
                            <option value="Bank" <?= ($editClient['payment_mode'] ?? '')==='Bank'?'selected':'' ?>>Bank</option>
                            <option value="PayPal" <?= ($editClient['payment_mode'] ?? '')==='PayPal'?'selected':'' ?>>PayPal</option>
                        </select>
                    </div>

                    <!-- 21. PayPal % -->
                    <div class="col-md-2">
                        <label class="form-label small">PayPal %</label>
                        <input type="number" name="paypal_percentage" id="paypal_percentage" class="form-control form-control-sm" step="0.01" min="0" max="100" value="<?= h($editClient['paypal_percentage'] ?? 0) ?>">
                    </div>

                    <!-- 21b. PayPal Email (Not in Excel but related) -->
                    <div class="col-md-2">
                        <label class="form-label small">PayPal Email</label>
                        <input type="text" name="paypal_email" id="paypal_email" class="form-control form-control-sm" value="<?= h($editClient['paypal_email'] ?? '') ?>">
                    </div>

                    <!-- 22. Extra System Info (Placed at the end) -->
                    <div class="col-12 mt-3 mb-1 border-top pt-2">
                        <h6 class="text-secondary fw-bold small mb-0"><i class="fas fa-cogs me-1"></i> System Settings</h6>
                    </div>
                    
                    <div class="col-md-4">
                        <label class="form-label small">Additional Email (Production)</label>
                        <input type="text" name="production_email" class="form-control form-control-sm" value="<?= h($editClient['production_email'] ?? '') ?>">
                    </div>

                    <div class="col-md-4">
                        <label class="form-label small">Preferred Communication</label>
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
                    
                </div>
                
                <div class="d-flex justify-content-end gap-2 mt-4 pt-3 border-top">
                    <a href="client_master.php" class="btn btn-light border shadow-sm px-4">Cancel</a>
                    <button type="submit" class="btn btn-primary shadow-sm px-4">
                        <i class="fas fa-save me-2"></i><?= $mode==='edit'?'Update Client':'Save Client' ?>
                    </button>
                </div>
            </form>
        </div>
      </div>

    <?php else: ?>
    
    <!-- LIST VIEW -->
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h4 class="fw-bold text-dark"><i class="fas fa-building text-primary me-2"></i>Client Master <?= $isPassive ? '<span class="badge bg-warning text-dark shadow-sm">Passive Clients</span>' : '' ?></h4>
            <p class="text-muted mb-0">Manage client directory and billing configurations</p>
        </div>
        <div>
            <button type="button" class="btn btn-warning text-dark fw-bold btn-sm shadow-sm me-2" data-bs-toggle="modal" data-bs-target="#importModal">
                <i class="fas fa-file-excel me-2"></i> Import Excel
            </button>
            <a href="client_master.php?mode=add" class="btn btn-primary btn-sm shadow-sm">
                <i class="fas fa-plus me-1"></i> Add Client
            </a>
        </div>
    </div>

    <?php if ($isPassive): ?>
    <div class="alert alert-info alert-dismissible fade show mb-3 shadow-sm border-0 border-start border-4 border-info" role="alert">
        <i class="fas fa-info-circle me-2"></i>
        Showing clients who have no jobs assigned yet.
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    <?php endif; ?>

    <!-- Search and Filter Form -->
    <div class="card mb-4">
        <div class="card-body py-3">
            <form method="get" class="row g-2 align-items-center">
                <div class="col-md-4">
                    <div class="input-group input-group-sm">
                        <span class="input-group-text bg-white text-muted border-end-0"><i class="fas fa-search"></i></span>
                        <!-- ADDED UPDATED PLACEHOLDER HERE TO REFLECT NEW SEARCH FIELDS -->
                        <input type="text" name="q" class="form-control border-start-0 ps-0" 
                               placeholder="Search client / company / contact / email / country / currency / payment..." value="<?= h($q) ?>" <?= $isPassive ? 'disabled' : '' ?>>
                    </div>
                </div>
                <div class="col-md-2">
                    <select name="status" class="form-select form-select-sm" id="statusFilter">
                        <option value="">All Status</option>
                        <option value="Active" <?= $fstatus==='Active'?'selected':'' ?>>Active</option>
                        <option value="Inactive" <?= $fstatus==='Inactive'?'selected':'' ?>>Inactive</option>
                        <option value="Passive" <?= $fstatus==='Passive'?'selected':'' ?>>Passive (No Jobs)</option>
                    </select>
                </div>
                <div class="col-md-3">
                    <select name="sort" class="form-select form-select-sm">
                        <?php foreach($sortable as $s): ?>
                            <option value="<?= $s ?>" <?= $sort===$s?'selected':'' ?>><?= ucwords(str_replace('_',' ',$s)) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                   <div class="col-md-2 d-flex gap-1">
                    <button type="submit" class="btn btn-primary btn-sm flex-grow-1 shadow-sm">Go</button>
                    <a href="client_master.php" class="btn btn-light border btn-sm flex-grow-1 shadow-sm">Clear</a>
                </div>
            </form>
        </div>
    </div>

    <!-- Table Container -->
    <div class="card">
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0" id="clientTable">
                    <thead class="table-light">
                        <tr>
                            <th>Sl No.</th>
                            <th>Country</th>
                            <th>Currency</th>
                            <th>Payment</th>
                            <th>Frequency</th>
                            <th>Y0J</th>
                            <th>Initial</th>
                            <th>Client Name</th>
                            <th>Invoice name</th>
                            <th>Invoice address</th>
                            <th>GST</th>
                            <th>Email_Invoice</th>
                            <th>Email_Files</th>
                            <th>Email_Payment</th>
                            <th>Link</th>
                            <th>Contact Number</th>
                            <th>Contact Person</th>
                            <th>GST %</th>
                            <th>CGST</th>
                            <th>SGST</th>
                            <th>Pay Mode</th>
                            <th>PayPal %</th>
                            <!--th>NDA</th-->
                            <th>Status</th>
                            <th class="text-center" style="width: 120px;">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if($clients && $clients->num_rows):$i=0;
                            while($row = $clients->fetch_assoc()): $i=$i+1;?>
                        <tr>
                            <td class="text-muted text-center"><?= $i ?></td>
                            <td><span class="badge bg-light text-dark border"><?= h($row['country']) ?></span></td>
                            <td><strong><?= h($row['currency'] ?? '') ?></strong></td>
                            <td><?= h($row['payment'] ?? '') ?></td>
                            <td><?= h($row['frequency'] ?? '') ?></td>
                            <td><span class="badge bg-info bg-opacity-10 text-info border border-info border-opacity-25"><?= !empty($row['date']) ? h($row['date']) : '—'; ?></span></td>
                            <td><strong><?= h($row['initial']) ?></strong></td>
                            <td class="fw-bold text-primary"><?= h($row['client_name']) ?></td>
                            <td><?= h($row['company_name']) ?></td>
                            <td>
                                <span class="d-inline-block text-truncate text-muted" style="max-width: 150px;" title="<?= h($row['address']) ?>">
                                    <?= h($row['address']) ?>
                                </span>
                            </td>
                            <td><?= h($row['gst_no']) ?></td>
                            <td><?= h($row['invoice_email']) ?></td>
                            <td><?= h($row['email_files']) ?></td>
                            <td><?= h($row['email']) ?></td>
                            <td>
                                <?php if(!empty($row['link'])): ?>
                                    <a href="<?php echo "http://". h($row['link']) ?>" target="_blank" class="text-decoration-none"><i class="fas fa-external-link-alt text-primary"></i> <?php echo "http://". h($row['link']) ?></a>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?= h($row['phone'])?> 
                                <?php if($row['additional_mobile']!='') { echo "<br><small class='text-muted'>" . h($row['additional_mobile']) . "</small>"; } ?>
                            </td>
                            <td><?= h($row['contact_person']) ?></td>
                            <td><?= number_format((float)($row['gst_per'] ?? 0), 2) ?></td>
                            <td><?= number_format((float)($row['cgst'] ?? 0), 2) ?></td>
                            <td><?= number_format((float)($row['sgst'] ?? 0), 2) ?></td>
                            <td><?= h($row['payment_mode'] ?? '') ?></td>
                            <td><?= number_format((float)($row['paypal_percentage'] ?? 0), 2) ?></td>
                            <!--td><?= h($row['nda']) ?></td-->
                            <td>
                                <form method="post" style="display:inline;" onsubmit="return confirm('Change status of <?= h($row['client_name']) ?> from <?= $row['status'] ?> to <?= $row['status']==='Active'?'Inactive':'Active' ?>?')">
                                    <input type="hidden" name="action" value="toggle">
                                    <input type="hidden" name="client_id" value="<?= (int)$row['client_id'] ?>">
                                    <button type="submit" class="btn btn-sm shadow-sm status-badge <?= $row['status']==='Active'?'btn-success':'btn-secondary' ?>">
                                        <?= h($row['status']) ?>
                                    </button>
                                </form>
                            </td>
                            <td class="text-center">
                                <div class="btn-group btn-group-sm shadow-sm" role="group">
                                    <!--a class="btn btn-outline-info" href="client_rate_list.php?client_id=<?= (int)$row['client_id'] ?>" title="View/Edit Rate List">
                                        <i class="fas fa-list-alt"></i>
                                    </a-->
                                    <a class="btn btn-outline-primary" href="client_master.php?mode=edit&id=<?= (int)$row['client_id'] ?>" title="Edit Client">
                                        <i class="fas fa-edit"></i>
                                    </a>
                                    <form method="post" style="display:inline;" onsubmit="return confirm('Delete client <?= h($row['client_name']) ?>?')">
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="client_id" value="<?= (int)$row['client_id'] ?>">
                                        <button class="btn btn-outline-danger" title="Delete Client" style="border-top-left-radius:0; border-bottom-left-radius:0;">
                                            <i class="fas fa-trash"></i>
                                        </button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                        <?php endwhile; else: ?>
                        <tr>
                            <td colspan="25" class="text-center py-5">
                                <div class="text-muted my-4">
                                    <i class="fas fa-folder-open fa-3x mb-3 text-secondary opacity-50"></i>
                                    <h5>No clients found</h5>
                                    <?php if ($error): ?>
                                        <p class="text-danger"><?= h($error) ?></p>
                                    <?php else: ?>
                                        <p class="mb-0">Try changing your search filters or add a new client.</p>
                                    <?php endif; ?>
                                    <a href="client_master.php?mode=add" class="btn btn-primary mt-3 shadow-sm">
                                        <i class="fas fa-plus me-1"></i> Add Client
                                    </a>
                                </div>
                            </td>
                        </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
            <div class="card-footer bg-white border-top text-muted text-center small py-3">
                <?php if($clients && $clients->num_rows): ?>
                    Showing <strong><?= $clients->num_rows ?></strong> clients.
                    <?php if ($isPassive): ?>
                        (Clients with no jobs)
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <?php endif; ?>

    <!-- IMPORT MODAL -->
    <div class="modal fade" id="importModal" tabindex="-1">
        <div class="modal-dialog modal-xl">
            <div class="modal-content border-0 shadow-lg">
                <div class="modal-header bg-warning text-dark border-0">
                    <h5 class="modal-title fw-bold">
                        <i class="fas fa-file-excel me-2"></i>Import Clients from Excel
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                
                <form action="" method="post" enctype="multipart/form-data">
                    <input type="hidden" name="action" value="import_excel">
                    
                    <div class="modal-body p-4">
                        <div class="row bg-light rounded p-3 mb-4 shadow-sm border">
                            <div class="col-md-8">
                                <div>
                                    <label class="form-label fw-bold text-dark">Select Excel File (.xlsx, .xls)</label>
                                    <input type="file" name="excel_file" class="form-control" accept=".xlsx, .xls" required>
                                    <small class="text-muted d-block mt-1"><i class="fas fa-info-circle me-1"></i>Maximum file size: 10MB</small>
                                </div>
                            </div>
                            <div class="col-md-4 d-flex align-items-end">
                                <button type="button" class="btn btn-success w-100 shadow-sm" onclick="downloadSample()">
                                    <i class="fas fa-download me-2"></i> Download Sample Format
                                </button>
                            </div>
                        </div>
                        
                        <div class="alert alert-secondary border-0 shadow-sm">
                            <h6 class="fw-bold text-dark mb-3"><i class="fas fa-list-ol me-2 text-primary"></i>Excel Format Requirements (24 Columns):</h6>
                            <p class="mb-2 text-muted small">Your Excel file must strictly follow this exact column ordering. Do not skip or add columns.</p>
                            <div class="table-responsive bg-white rounded shadow-sm border mb-3">
                                <table class="table table-sm table-bordered mb-0 align-middle">
                                    <thead class="table-light text-center small">
                                        <tr>
                                            <th>Col</th><th>Field Name</th>
                                            <th>Col</th><th>Field Name</th>
                                            <th>Col</th><th>Field Name</th>
                                            <th>Col</th><th>Field Name</th>
                                        </tr>
                                    </thead>
                                    <tbody class="small text-center">
                                        <tr><td>A</td><td>Sl No.</td><td>G</td><td>Initial</td><td>M</td><td>Email_Files</td><td>S</td><td>CGST</td></tr>
                                        <tr><td>B</td><td>Country</td><td>H</td><td class="fw-bold text-primary">Client Name (Req)</td><td>N</td><td>Email_Payment Queries</td><td>T</td><td>SGST</td></tr>
                                        <tr><td>C</td><td>Currency</td><td>I</td><td>Invoice name</td><td>O</td><td>Link</td><td>U</td><td>Payment mode</td></tr>
                                        <tr><td>D</td><td>Payment</td><td>J</td><td>Invoice address</td><td>P</td><td>Contact Number</td><td>V</td><td>PayPal %</td></tr>
                                        <tr><td>E</td><td>Frequency</td><td>K</td><td>GST</td><td>Q</td><td>Contact Person</td><td>W</td><td>Status</td></tr>
                                        <tr><td>F</td><td>Y0J (Year)</td><td>L</td><td>Email_Invoice</td><td>R</td><td>GST %</td><td>X</td><td class="fw-bold text-success">Created Date</td></tr>
                                    </tbody>
                                </table>
                            </div>
                            <div class="row text-muted small">
                                <div class="col-md-6">
                                    <ul class="mb-0">
                                        <li><i class="fas fa-check text-success me-1"></i>First row must be headers (skipped automatically).</li>
                                        <li><i class="fas fa-exclamation-circle text-warning me-1"></i><strong>Client Name</strong> (Column H) is mandatory to recognize rows.</li>
                                    </ul>
                                </div>
                                <div class="col-md-6">
                                    <ul class="mb-0">
                                        <li><i class="fas fa-plus-circle text-primary me-1"></i>New names will be <span class="badge bg-success">INSERTED</span></li>
                                        <li><i class="fas fa-sync text-info me-1"></i>Existing names will be <span class="badge bg-warning text-dark">UPDATED</span></li>
                                    </ul>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="modal-footer bg-light border-0">
                        <button type="button" class="btn btn-outline-secondary px-4" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-dark px-4 shadow-sm">
                            <i class="fas fa-cloud-upload-alt me-2"></i> Import Data
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
    function toggleTaxFields() {
        const countryField = document.getElementById('country');
        const addressField = document.getElementById('address');
        
        if(!countryField || !addressField) return;

        const country = countryField.value.trim().toLowerCase();
        const address = addressField.value.trim().toLowerCase();
        
        const gstNo = document.getElementById('gst_no');
        const gstPer = document.getElementById('gst_per');
        const cgst = document.getElementById('cgst');
        const sgst = document.getElementById('sgst');
        const paypalEmail = document.getElementById('paypal_email');
        const paypalPercentage = document.getElementById('paypal_percentage');
        const currencySelect = document.getElementById('currency');

        // Helper to reset fields
        const setFieldsState = (fields, disable, bgColor, val) => {
            fields.forEach(field => {
                if (field) {
                    field.disabled = disable;
                    field.style.backgroundColor = bgColor;
                    if(val !== null) field.value = val;
                }
            });
        };

        // Reset everything to enabled first
        setFieldsState([gstNo, gstPer, cgst, sgst, paypalEmail, paypalPercentage], false, '', null);
        
        if (country === 'india' || country === 'ind') {
            // Auto select INR if available
            if (currencySelect) {
                let foundINR = false;
                for (let i = 0; i < currencySelect.options.length; i++) {
                    if (currencySelect.options[i].value === 'INR') {
                        currencySelect.selectedIndex = i;
                        foundINR = true;
                        break;
                    }
                }
            }
            
            // Check Tamilnadu in address
            const isTamilnadu = address.includes('tamil') || address.includes('tamilnadu') || address.includes('tamil nadu');
            
            if (isTamilnadu) {
                // In TN: Disable Paypal, Enable all GST
                setFieldsState([paypalEmail], true, '#e9ecef', '');
                setFieldsState([paypalPercentage], true, '#e9ecef', '0');
            } else {
                // Outside TN: Disable CGST/SGST, Enable Paypal
                setFieldsState([cgst, sgst], true, '#e9ecef', '0');
            }

        } else {
            // Foreign Country
            if (currencySelect && currencySelect.value === 'INR') {
                for (let i = 0; i < currencySelect.options.length; i++) {
                    if (currencySelect.options[i].value === 'USD') {
                        currencySelect.selectedIndex = i;
                        break;
                    }
                }
            }
            
            // Disable all GST, Enable Paypal
            setFieldsState([gstNo], true, '#e9ecef', '');
            setFieldsState([gstPer, cgst, sgst], true, '#e9ecef', '0');
        }
    }

    // Download sample Excel file formatted for clients
    function downloadSample() {
        const sampleContent = "Sl No.,Country,Currency,Payment,Frequency,Y0J,Initial,Client Name,Invoice name,Invoice address,GST,Email_Invoice,Email_Files,Email_Payment Queries,Link,Contact Number,Contact Person,GST %,CGST,SGST,Payment mode,PayPal %,Status,Created Date\n" +
                             "1,India,INR,Net 30,Monthly,2026,ABC,ABC Corp,ABC Inc.,123 Main St Tamilnadu,22AAAAA0000A1Z5,inv@abc.com,files@abc.com,pay@abc.com,https://abc.com,9876543210,John Doe,18,9,9,Bank,0,Active,2026-03-02 10:00:00\n" +
                             "2,USA,USD,Due on Receipt,Weekly,2026,XYZ,XYZ LLC,XYZ LLC,456 Market St,,billing@xyz.com,,,https://xyz.com,1234567890,Jane Smith,0,0,0,PayPal,5,Active,2026-03-02 11:30:00";
        
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

    document.addEventListener('DOMContentLoaded', function() {
        // Initializing tax fields toggle logic
        toggleTaxFields();

        // Auto-submit form on filter changes
        const filterSelects = document.querySelectorAll('select[name="status"], select[name="sort"], select[name="dir"]');
        filterSelects.forEach(select => {
            select.addEventListener('change', function() {
                this.form.submit();
            });
        });

        // Auto-dismiss alerts after 5 seconds
        const alerts = document.querySelectorAll('.alert');
        alerts.forEach(alert => {
            setTimeout(() => {
                if (alert.isConnected) {
                    const bsAlert = new bootstrap.Alert(alert);
                    bsAlert.close();
                }
            }, 5000);
        });
    });
    </script>
</body>
</html>