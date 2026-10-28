<?php
require 'db.php';
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
if (session_status() === PHP_SESSION_NONE) { session_start(); }

// Check if it's an Excel import request
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'import_excel') {
    if (isset($_FILES['excel_file']) && $_FILES['excel_file']['error'] === UPLOAD_ERR_OK) {
        $file = $_FILES['excel_file'];
        $fileName = $file['name'];
        $fileTmpPath = $file['tmp_name'];
        $fileSize = $file['size'];
        
        // Check file extension
        $allowedExtensions = ['xls', 'xlsx'];
        $fileExtension = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
        
        if (!in_array($fileExtension, $allowedExtensions)) {
            header('Location: client_master.php?msg=' . urlencode('Only Excel files (.xls, .xlsx) are allowed.') . '&type=danger');
            exit;
        }
        
        // Check file size (max 10MB)
        if ($fileSize > 10485760) {
            header('Location: client_master.php?msg=' . urlencode('File size must be less than 10MB.') . '&type=danger');
            exit;
        }
        
        // Try multiple paths for SimpleXLSX library
        $libPath = dirname(__DIR__) . '/simplexlsx-master/src/SimpleXLSX.php';
        if (!file_exists($libPath)) {
            $libPath = dirname(__DIR__) . '/simplexlsx-master/SimpleXLSX.php';
        }
        if (!file_exists($libPath)) {
            $libPath = 'simplexlsx-master/src/SimpleXLSX.php';
        }
        if (!file_exists($libPath)) {
            $libPath = 'simplexlsx-master/SimpleXLSX.php';
        }
        
        if (!file_exists($libPath)) {
            // Try to find it in common locations
            $possiblePaths = [
                __DIR__ . '/simplexlsx-master/src/SimpleXLSX.php',
                __DIR__ . '/simplexlsx-master/SimpleXLSX.php',
                '../simplexlsx-master/src/SimpleXLSX.php',
                '../simplexlsx-master/SimpleXLSX.php',
                '../../simplexlsx-master/src/SimpleXLSX.php',
                '../../simplexlsx-master/SimpleXLSX.php'
            ];
            
            foreach ($possiblePaths as $path) {
                if (file_exists($path)) {
                    $libPath = $path;
                    break;
                }
            }
        }
        
        if (!file_exists($libPath)) {
            header('Location: client_master.php?msg=' . urlencode('SimpleXLSX library not found. Please install it.') . '&type=danger');
            exit;
        }
        
        // Include SimpleXLSX
        require_once $libPath;
        
        try {
            // Parse Excel file
            if ($xlsx = SimpleXLSX::parse($fileTmpPath)) {
                $rows = $xlsx->rows();
                
                if (empty($rows) || count($rows) < 2) {
                    header('Location: client_master.php?msg=' . urlencode('Excel file is empty or has no data.') . '&type=danger');
                    exit;
                }
                
                $importedCount = 0;
                $updatedCount = 0;
                $skippedCount = 0;
                $errors = [];
                
                // Start from row 2 (skip header row)
                for ($i = 1; $i < count($rows); $i++) {
                    $row = $rows[$i];
                    
                    // Skip empty rows
                    if (empty(array_filter($row))) {
                        continue;
                    }
                    
                    // Ensure row has enough columns, fill missing with empty strings
                    while (count($row) < 29) {
                        $row[] = '';
                    }
                    
                    // Map Excel columns to database fields (0-based index for SimpleXLSX)
                    $client_name = trim($row[1] ?? ''); // Column B (index 1)
                    $initial = trim($row[2] ?? ''); // Column C
                    $company = trim($row[3] ?? ''); // Column D
                    $contact = trim($row[4] ?? ''); // Column E
                    $email = trim($row[5] ?? ''); // Column F
                    $general_email = trim($row[6] ?? ''); // Column G
                    $invoice_email = trim($row[7] ?? ''); // Column H
                    $production_email = trim($row[8] ?? ''); // Column I
                    $phone = trim($row[9] ?? ''); // Column J
                    $additional_mobile = trim($row[10] ?? ''); // Column K
                    $address = trim($row[11] ?? ''); // Column L
                    $additional_address = trim($row[12] ?? ''); // Column M
                    $country = trim($row[13] ?? 'India'); // Column N
                    $gst = trim($row[14] ?? ''); // Column O
                    $pref = trim($row[15] ?? 'Email'); // Column P
                    $language = trim($row[16] ?? 'English'); // Column Q
                    $paypal_email = trim($row[17] ?? ''); // Column R
                    $currency = trim($row[18] ?? ($country === 'India' ? 'INR' : 'USD')); // Column S
                    $nda = trim($row[19] ?? ''); // Column T
                    $date = trim($row[20] ?? date('Y-m-d')); // Column U
                    
                    // Numeric fields
                    $word_rate = floatval($row[21] ?? 0); // Column V
                    $page_rate = floatval($row[22] ?? 0); // Column W
                    $math_rate = floatval($row[23] ?? 0); // Column X
                    $ref_rate = floatval($row[24] ?? 0); // Column Y
                    $gst_per = floatval($row[25] ?? 0); // Column Z
                    $cgst = floatval($row[26] ?? 0); // Column AA
                    $igst = floatval($row[27] ?? 0); // Column AB
                    $paypal_percentage = floatval($row[28] ?? 0); // Column AC
                    
                    // Validate required field
                    if (empty($client_name)) {
                        $skippedCount++;
                        $errors[] = "Row " . ($i + 1) . ": Skipped - Client Name is required";
                        continue;
                    }
                    
                    // Apply country-based logic
                    if (strtolower($country) === 'india') {
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
                    
                    // Validate date format
                    if (!empty($date)) {
                        $date = date('Y-m-d', strtotime($date));
                        if ($date === '1970-01-01') {
                            $date = date('Y-m-d'); // Default to today if invalid
                        }
                    } else {
                        $date = date('Y-m-d');
                    }
                    
                    // Check if client already exists (by client_name or email)
                    $client_id = null;
                    if (!empty($email)) {
                        $checkStmt = $mysqli->prepare("SELECT client_id FROM client_master WHERE email = ?");
                        $checkStmt->bind_param("s", $email);
                        $checkStmt->execute();
                        $checkResult = $checkStmt->get_result();
                        if ($checkResult->num_rows > 0) {
                            $existingClient = $checkResult->fetch_assoc();
                            $client_id = $existingClient['client_id'];
                        }
                        $checkStmt->close();
                    }
                    
                    if (!$client_id && !empty($client_name)) {
                        // Also check by client name
                        $checkStmt = $mysqli->prepare("SELECT client_id FROM client_master WHERE client_name = ?");
                        $checkStmt->bind_param("s", $client_name);
                        $checkStmt->execute();
                        $checkResult = $checkStmt->get_result();
                        if ($checkResult->num_rows > 0) {
                            $existingClient = $checkResult->fetch_assoc();
                            $client_id = $existingClient['client_id'];
                        }
                        $checkStmt->close();
                    }
                    
                    if ($client_id) {
                        // Update existing client
                        $updateStmt = $mysqli->prepare("UPDATE client_master SET
                            initial=?, company_name=?, contact_person=?, email=?, general_email=?, invoice_email=?, production_email=?,
                            phone=?, additional_mobile=?, address=?, additional_address=?, country=?, gst_no=?, preferred_communication=?, preferred_language=?,
                            word_rate=?, page_rate=?, math_rate=?, ref_rate=?, gst_per=?, cgst=?, igst=?, currency=?, paypal_email=?, paypal_percentage=?, nda=?, date=?
                            WHERE client_id=?");
                        
                        if (!$updateStmt) {
                            $errors[] = "Row " . ($i + 1) . ": Update prepare failed - " . $mysqli->error;
                            $skippedCount++;
                            continue;
                        }
                        
                        $updateStmt->bind_param(
                            "sssssssssssssssdddddddssdsssi",
                            $initial, $company, $contact, $email, $general_email, $invoice_email, $production_email,
                            $phone, $additional_mobile, $address, $additional_address, $country, $gst, $pref, $language,
                            $word_rate, $page_rate, $math_rate, $ref_rate, $gst_per, $cgst, $igst, $currency, $paypal_email, $paypal_percentage, $nda, $date, $client_id
                        );
                        
                        if ($updateStmt->execute()) {
                            $updatedCount++;
                        } else {
                            $errors[] = "Row " . ($i + 1) . ": Update failed - " . $updateStmt->error;
                            $skippedCount++;
                        }
                        $updateStmt->close();
                    } else {
                        // Insert new client
                        $insertStmt = $mysqli->prepare("INSERT INTO client_master 
                            (client_name, initial, company_name, contact_person, email, general_email, invoice_email, production_email, phone, additional_mobile, address, additional_address, country, gst_no, cgst, igst, paypal_email, paypal_percentage, preferred_communication, preferred_language, created_date, status, word_rate, page_rate, math_rate, ref_rate, gst_per, currency, nda, date) 
                            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), 'Active', ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                        
                        if (!$insertStmt) {
                            $errors[] = "Row " . ($i + 1) . ": Insert prepare failed - " . $mysqli->error;
                            $skippedCount++;
                            continue;
                        }
                        
                        $insertStmt->bind_param(
                            "ssssssssssssssdddsdsssddddddssss",
                            $client_name, $initial, $company, $contact, $email, $general_email, $invoice_email, $production_email,
                            $phone, $additional_mobile, $address, $additional_address, $country, $gst,
                            $cgst, $igst, $paypal_email, $paypal_percentage, $pref, $language,
                            $word_rate, $page_rate, $math_rate, $ref_rate, $gst_per, $currency, $nda, $date
                        );
                        
                        if ($insertStmt->execute()) {
                            $importedCount++;
                        } else {
                            $errors[] = "Row " . ($i + 1) . ": Insert failed - " . $insertStmt->error;
                            $skippedCount++;
                        }
                        $insertStmt->close();
                    }
                }
                
                // Prepare success message
                $message = "Excel import completed: $importedCount new clients added, $updatedCount clients updated, $skippedCount rows skipped.";
                
                // Add errors if any
                if (!empty($errors)) {
                    $message .= " Errors: " . implode("; ", array_slice($errors, 0, 5));
                    if (count($errors) > 5) {
                        $message .= " and " . (count($errors) - 5) . " more errors.";
                    }
                }
                
                header('Location: client_master.php?msg=' . urlencode($message) . '&type=success');
                exit;
                
            } else {
                $errorMsg = SimpleXLSX::parseError();
                header('Location: client_master.php?msg=' . urlencode('Failed to parse Excel file: ' . $errorMsg) . '&type=danger');
                exit;
            }
            
        } catch (Exception $e) {
            header('Location: client_master.php?msg=' . urlencode('Error importing Excel file: ' . $e->getMessage()) . '&type=danger');
            exit;
        }
    } else {
        header('Location: client_master.php?msg=' . urlencode('Please select an Excel file to upload.') . '&type=danger');
        exit;
    }
} else {
    header('Location: client_master.php');
    exit;
}
?>