<?php
session_start();
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Start output buffering
ob_start();

require 'db.php';

// Define the target table
$target_table = 'paypal_transactions';

// Include Excel library
require_once __DIR__ . '/simplexlsx-master/src/SimpleXLSX.php';
use Shuchkin\SimpleXLSX;

// Log start
error_log("=== PAYPAL IMPORT STARTED ===");

// Check if form was submitted
if (isset($_POST['save_paypal_excel_data'])) {
    error_log("Form submitted successfully");
    
    // --- File Validation ---
    if (!isset($_FILES['import_file']) || $_FILES['import_file']['error'] !== UPLOAD_ERR_OK) {
        $upload_errors = [
            UPLOAD_ERR_NO_FILE => 'Please select a file to upload.',
            UPLOAD_ERR_INI_SIZE => 'File size exceeds upload_max_filesize.',
            UPLOAD_ERR_FORM_SIZE => 'File size exceeds MAX_FILE_SIZE.',
            UPLOAD_ERR_PARTIAL => 'File was only partially uploaded.',
            UPLOAD_ERR_NO_TMP_DIR => 'Missing temporary folder.',
            UPLOAD_ERR_CANT_WRITE => 'Failed to write file to disk.',
            UPLOAD_ERR_EXTENSION => 'A PHP extension stopped the file upload.'
        ];
        $error_code = $_FILES['import_file']['error'] ?? UPLOAD_ERR_NO_FILE;
        $error_msg = "❌ File upload error: " . ($upload_errors[$error_code] ?? 'Unknown error');
        $_SESSION['message'] = $error_msg;
        error_log($error_msg);
        header('Location: paypalstatement.php');
        exit(0);
    }

    $fileName = $_FILES['import_file']['name'];
    $inputFileNamePath = $_FILES['import_file']['tmp_name'];
    $allowed_ext = ['xlsx', 'xls', 'csv'];    
    $file_ext = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));

    error_log("Processing file: $fileName, extension: $file_ext");

    if (!in_array($file_ext, $allowed_ext)) {
        $error_msg = "❌ Invalid file type. Please upload .xlsx, .xls, or .csv files only.";
        $_SESSION['message'] = $error_msg;
        error_log($error_msg);
        header('Location: paypalstatement.php');
        exit(0);
    }
    
    if ($_FILES['import_file']['size'] > 10 * 1024 * 1024) {
        $error_msg = "❌ File size too large. Maximum 10MB allowed.";
        $_SESSION['message'] = $error_msg;
        error_log($error_msg);
        header('Location: paypalstatement.php');
        exit(0);
    }

    if (!file_exists($inputFileNamePath)) {
        $error_msg = "❌ Uploaded file not found at temporary path.";
        $_SESSION['message'] = $error_msg;
        error_log($error_msg);
        header('Location: paypalstatement.php');
        exit(0);
    }

    try {
        // --- File Loading ---
        error_log("Starting file parsing...");
        
        if ($file_ext === 'csv') {
            error_log("Processing CSV file");
            $data = parseCSV($inputFileNamePath, ',');
            
            if (isset($data[0]) && count($data[0]) <= 1) {
                error_log("CSV parse failed with comma. Trying semicolon delimiter...");
                $data = parseCSV($inputFileNamePath, ';');
            }
            
        } else {
            error_log("Processing Excel file");
            $xlsx = SimpleXLSX::parse($inputFileNamePath);
            if (!$xlsx) {
                throw new Exception('Error reading or parsing the Excel file. Check file integrity. ' . SimpleXLSX::parseError());
            }
            $data = $xlsx->rows();    
        }

        error_log("File parsed successfully. Total rows: " . count($data));

        if (empty($data)) {
            $error_msg = "❌ The file is empty or could not be read.";
            $_SESSION['message'] = $error_msg;
            error_log($error_msg);
            header('Location: paypalstatement.php');
            exit(0);
        }

        // Show first few rows for debugging
        error_log("First 3 rows of data:");
        for ($i = 0; $i < min(3, count($data)); $i++) {
            error_log("Row $i: " . json_encode($data[$i]));
        }

        // --- Database Import ---
        $imported_rows = 0;
        $skipped_rows = 0;
        $duplicate_rows = 0;
        
        error_log("Starting database transaction...");
        $mysqli->begin_transaction();
        $is_error = false;
        
        // Prepare the INSERT statement
        $sql = "INSERT INTO $target_table (
            date, time, timezone, name, type, status, currency, gross, fee, net,
            from_email, to_email, transaction_id, shipping_address, address_status,
            item_title, item_id, shipping_amount, insurance_amount, sales_tax,
            option1_name, option1_value, option2_name, option2_value, reference_txn_id,
            invoice_number, custom_number, quantity, receipt_id, balance,
            address_line_1, address_line_2, city, state, zip_code, country,
            contact_phone_number, subject, note, country_code, balance_impact,
            imported_at
        ) VALUES (
            ?, ?, ?, ?, ?, ?, ?, ?, ?, ?,
            ?, ?, ?, ?, ?, ?, ?, ?, ?, ?,
            ?, ?, ?, ?, ?, ?, ?, ?, ?, ?,
            ?, ?, ?, ?, ?, ?, ?, ?, ?, ?,
            NOW()
        )";
        
        error_log("Preparing SQL statement");
        $stmt = $mysqli->prepare($sql);
        if (!$stmt) {
            throw new Exception("Database prepare error: " . $mysqli->error);
        }
        error_log("SQL prepared successfully");
        
        // Define variables for binding
        $date = $time = $timezone = $name = $type = $status = $currency = '';
        $gross = $fee = $net = $shipping_amount = $insurance_amount = $sales_tax = $balance = 0;
        $from_email = $to_email = $transaction_id = $shipping_address = $address_status = '';
        $item_title = $item_id = $option1_name = $option1_value = $option2_name = $option2_value = '';
        $reference_txn_id = $invoice_number = $custom_number = $receipt_id = '';
        $address_line_1 = $address_line_2 = $city = $state = $zip_code = $country = '';
        $contact_phone_number = $subject = $note = $country_code = $balance_impact = '';
        $quantity = 0;

        // Bind parameters
        $types = str_repeat("s", 40);
        $bind_params = [$types];
        $bind_params[] = &$date; $bind_params[] = &$time; $bind_params[] = &$timezone; $bind_params[] = &$name;
        $bind_params[] = &$type; $bind_params[] = &$status; $bind_params[] = &$currency; $bind_params[] = &$gross;
        $bind_params[] = &$fee; $bind_params[] = &$net; $bind_params[] = &$from_email; $bind_params[] = &$to_email;
        $bind_params[] = &$transaction_id; $bind_params[] = &$shipping_address; $bind_params[] = &$address_status;
        $bind_params[] = &$item_title; $bind_params[] = &$item_id; $bind_params[] = &$shipping_amount;
        $bind_params[] = &$insurance_amount; $bind_params[] = &$sales_tax; $bind_params[] = &$option1_name;
        $bind_params[] = &$option1_value; $bind_params[] = &$option2_name; $bind_params[] = &$option2_value;
        $bind_params[] = &$reference_txn_id; $bind_params[] = &$invoice_number; $bind_params[] = &$custom_number;
        $bind_params[] = &$quantity; $bind_params[] = &$receipt_id; $bind_params[] = &$balance;
        $bind_params[] = &$address_line_1; $bind_params[] = &$address_line_2; $bind_params[] = &$city;
        $bind_params[] = &$state; $bind_params[] = &$zip_code; $bind_params[] = &$country;
        $bind_params[] = &$contact_phone_number; $bind_params[] = &$subject; $bind_params[] = &$note;
        $bind_params[] = &$country_code; $bind_params[] = &$balance_impact;
        
        if (!call_user_func_array([$stmt, 'bind_param'], $bind_params)) {
            throw new Exception("bind_param failed: " . $stmt->error);
        }
        error_log("Parameters bound successfully");
        
        // Determine starting row (skip header if present)
        $start_row = 0;
        if (isset($data[0]) && (
            (isset($data[0][0]) && strtolower($data[0][0]) === 'date') || 
            (isset($data[0][12]) && strtolower(trim($data[0][12])) === 'transaction id')
        )) {
             $start_row = 1;
             error_log("Header row detected, starting from row 1");
        }

        // Process rows
        error_log("Processing rows from $start_row to " . (count($data) - 1));
        
        for ($i = $start_row; $i < count($data); $i++) {
            $row = $data[$i];
            
            // Skip empty rows
            if (empty(array_filter($row, fn($value) => $value !== null && $value !== '' && $value !== ' '))) {
                $skipped_rows++;
                continue;
            }
            
            // Ensure we have enough columns
            if (count($row) < 41) {
                $row = array_pad($row, 41, '');
            }

            // Extract data
            $date = $row[0] ?? '';
            $time = $row[1] ?? '';
            $timezone = $row[2] ?? '';
            $name = $row[3] ?? '';
            $type = $row[4] ?? '';
            $status = $row[5] ?? '';
            $currency = $row[6] ?? '';
            $gross = $row[7] ?? 0;
            $fee = $row[8] ?? 0;
            $net = $row[9] ?? 0;
            $from_email = $row[10] ?? '';
            $to_email = $row[11] ?? '';
            $transaction_id = $row[12] ?? '';
            $shipping_address = $row[13] ?? '';
            $address_status = $row[14] ?? '';
            $item_title = $row[15] ?? '';
            $item_id = $row[16] ?? '';
            $shipping_amount = $row[17] ?? 0;
            $insurance_amount = $row[18] ?? 0;
            $sales_tax = $row[19] ?? 0;
            $option1_name = $row[20] ?? '';
            $option1_value = $row[21] ?? '';
            $option2_name = $row[22] ?? '';
            $option2_value = $row[23] ?? '';
            $reference_txn_id = $row[24] ?? '';
            $invoice_number = $row[25] ?? '';
            $custom_number = $row[26] ?? '';
            $quantity = $row[27] ?? 0;
            $receipt_id = $row[28] ?? '';
            $balance = $row[29] ?? 0;
            $address_line_1 = $row[30] ?? '';
            $address_line_2 = $row[31] ?? '';
            $city = $row[32] ?? '';
            $state = $row[33] ?? '';
            $zip_code = $row[34] ?? '';
            $country = $row[35] ?? '';
            $contact_phone_number = $row[36] ?? '';
            $subject = $row[37] ?? '';
            $note = $row[38] ?? '';
            $country_code = $row[39] ?? '';
            $balance_impact = $row[40] ?? '';

            // Validate required fields
            $transaction_id = trim($transaction_id);
            $date = trim($date);
            
            if (empty($transaction_id) || empty($date)) {
                $skipped_rows++;
                continue;
            }

            // Check for duplicate
            $check_sql = "SELECT id FROM $target_table WHERE transaction_id = ?";
            $check_stmt = $mysqli->prepare($check_sql);
            if ($check_stmt) {
                $check_stmt->bind_param("s", $transaction_id);
                $check_stmt->execute();
                $check_stmt->store_result();
                
                if ($check_stmt->num_rows > 0) {
                    $check_stmt->close();
                    $duplicate_rows++;
                    continue;
                }
                $check_stmt->close();
            }

            // Convert date
            $date = excelToDate($date);
            $date = preg_replace('/\s.+$/', '', $date);

            // Convert numeric fields
            $gross = (string)floatval(str_replace(',', '', $gross));
            $fee = (string)floatval(str_replace(',', '', $fee));
            $net = (string)floatval(str_replace(',', '', $net));
            $shipping_amount = (string)floatval(str_replace(',', '', $shipping_amount));
            $insurance_amount = (string)floatval(str_replace(',', '', $insurance_amount));
            $sales_tax = (string)floatval(str_replace(',', '', $sales_tax));
            $balance = (string)floatval(str_replace(',', '', $balance));
            $quantity = (string)intval($quantity);

            // Execute insert
            if (!$stmt->execute()) {
                $is_error = true;
                $error_msg = "❌ Insert failed on row " . ($i + 1) . ": " . $stmt->error;
                $_SESSION['message'] = $error_msg;
                error_log($error_msg);
                break;
            }
            $imported_rows++;
        }
        
        $stmt->close();
        error_log("Processing complete. Imported: $imported_rows, Skipped: $skipped_rows, Duplicates: $duplicate_rows");

        // Finalize transaction
        if ($is_error) {
            $mysqli->rollback();
            error_log("Transaction rolled back");
        } else {
            $mysqli->commit();
            $message = "✅ **PayPal Import Success!** Imported **$imported_rows** transaction records.";
            if ($skipped_rows > 0) {
                $message .= " Skipped **$skipped_rows** rows.";
            }
            if ($duplicate_rows > 0) {
                $message .= " Skipped **$duplicate_rows** duplicates.";
            }
            $_SESSION['message'] = $message;
            error_log("Transaction committed: $message");
        }

    } catch (\Exception $e) {
        if (isset($mysqli)) {
            $mysqli->rollback();
        }
        $error_msg = "❌ Import error: " . $e->getMessage();
        $_SESSION['message'] = $error_msg;
        error_log("PayPal import error: " . $e->getMessage());
    }

} else {
    error_log("Form not submitted correctly");
    $_SESSION['message'] = "❌ Form submission error. Please try again.";
}

// Redirect back
ob_end_clean();
header('Location: paypalstatement.php');
exit;

/**
 * Convert Excel date to MySQL date
 */
function excelToDate($excelDate) {
    if (empty($excelDate)) return '';
    
    if (is_numeric($excelDate) && $excelDate > 1) {
        $unixTimestamp = ($excelDate - 25569) * 86400;
        return date('Y-m-d', $unixTimestamp);
    }
    
    $timestamp = strtotime($excelDate);
    if ($timestamp !== false) {
        return date('Y-m-d', $timestamp);
    }
    
    return $excelDate;
}

/**
 * Parse CSV file
 */
function parseCSV($filename, $delimiter) {
    $data = [];
    if (($handle = fopen($filename, 'r')) !== FALSE) {
        while (($row = fgetcsv($handle, 0, $delimiter, '"')) !== FALSE) {
            $data[] = $row;
        }
        fclose($handle);
    }
    return $data;
}
?>