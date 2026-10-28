<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Start output buffering to prevent header errors
ob_start();

session_start();
require 'db.php';

// --- INCLUDE EXCEL LIBRARIES ---
require_once __DIR__ . '/simplexlsx-master/src/SimpleXLSX.php';
use Shuchkin\SimpleXLSX;

// Define the TARGET TABLE NAME
$target_table = 'bank_transactions';

if (isset($_POST['save_excel_data'])) {
    
    // Check for file upload errors
    if ($_FILES['import_file']['error'] !== UPLOAD_ERR_OK) {
        $upload_errors = [
            UPLOAD_ERR_INI_SIZE => 'File size exceeds upload_max_filesize',
            UPLOAD_ERR_FORM_SIZE => 'File size exceeds MAX_FILE_SIZE',
            UPLOAD_ERR_PARTIAL => 'File was only partially uploaded',
            UPLOAD_ERR_NO_FILE => 'No file was uploaded',
            UPLOAD_ERR_NO_TMP_DIR => 'Missing temporary folder',
            UPLOAD_ERR_CANT_WRITE => 'Failed to write file to disk',
            UPLOAD_ERR_EXTENSION => 'A PHP extension stopped the file upload'
        ];
        $_SESSION['message'] = "❌ File upload error: " . ($upload_errors[$_FILES['import_file']['error']] ?? 'Unknown error');
        header('Location: bankstatement.php');
        exit(0);
    }

    $fileName = $_FILES['import_file']['name'];
    $inputFileNamePath = $_FILES['import_file']['tmp_name'];
    $allowed_ext = ['xlsx', 'xls', 'csv']; 
    $file_ext = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));

    // Validate file extension
    if (!in_array($file_ext, $allowed_ext)) {
        $_SESSION['message'] = "❌ Invalid file type. Please upload .xlsx, .xls, or .csv files only.";
        header('Location: bankstatement.php');
        exit(0);
    }

    try {
        // Load the spreadsheet using SimpleXLSX
        $xlsx = SimpleXLSX::parse($inputFileNamePath);
        if (!$xlsx) {
            throw new Exception('Error reading or parsing the Excel file. Check file integrity. ' . SimpleXLSX::parseError());
        }
        $data = $xlsx->rows(); 

        if (empty($data)) {
            $_SESSION['message'] = "❌ The Excel file is empty or could not be read.";
            header('Location: bankstatement.php');
            exit(0);
        }

        $imported_rows = 0;
        $skipped_rows = 0;
        
        // Start transaction for atomic operation
        $mysqli->begin_transaction();
        $is_error = false;
        
        // Prepare the INSERT statement for bank_transactions table
        $sql = "INSERT INTO $target_table (
            sn, trans_id, value_date, transaction_date, transaction_posted_date, 
            cheque_ref_no, transaction_remarks, withdrawal, deposit, balance, created_at
        ) VALUES (
            ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW()
        )";
        
        $stmt = $mysqli->prepare($sql);
        if (!$stmt) {
            throw new Exception("Database prepare error: " . $mysqli->error);
        }
        
        // Binding string: 10 parameters (removed remarks and date)
        $types = "sssssssdds"; 
        
        $stmt->bind_param(
            $types, 
            $sn, $trans_id, $value_date, $transaction_date, $transaction_posted_date,
            $cheque_ref_no, $transaction_remarks, $withdrawal, $deposit, $balance
        );
        
        // Loop through rows and insert into database
        $is_header = true;
        foreach ($data as $index => $row) {
            if ($is_header) {
                $is_header = false; 
                continue;
            }

            // Skip empty rows
            if (empty(array_filter($row, function($value) { 
                return $value !== null && $value !== ''; 
            }))) {
                $skipped_rows++;
                continue;
            }
            
            // Map Excel columns to database fields - only process first 10 columns
            $sn                      = !empty($row[0]) ? trim($row[0]) : '';
            $trans_id                = !empty($row[1]) ? trim($row[1]) : '';
            $value_date              = !empty($row[2]) ? trim($row[2]) : '';
            $transaction_date        = !empty($row[3]) ? trim($row[3]) : '';
            $transaction_posted_date = !empty($row[4]) ? trim($row[4]) : '';
            $cheque_ref_no           = !empty($row[5]) ? trim($row[5]) : '';
            $transaction_remarks     = !empty($row[6]) ? trim($row[6]) : '';
            
            // Handle empty monetary values - set to 0 instead of empty
            $withdrawal              = !empty($row[7]) ? floatval($row[7]) : 0;
            $deposit                 = !empty($row[8]) ? floatval($row[8]) : 0;
            $balance                 = !empty($row[9]) ? floatval($row[9]) : 0;
            
            // REMOVED: remarks and date columns are not imported
            // They will be empty/NULL in the database

            // Validate required fields - removed balance requirement since it's empty in your file
            if (empty($trans_id) || empty($transaction_date)) {
                $skipped_rows++;
                error_log("Skipping row $index due to missing transaction ID or date");
                continue;
            }

            // Convert date formats if needed
            if (!empty($value_date)) {
                $value_date = excelToDate($value_date);
            }
            if (!empty($transaction_date)) {
                $transaction_date = excelToDate($transaction_date);
            }
            if (!empty($transaction_posted_date)) {
                $transaction_posted_date = excelToDate($transaction_posted_date);
            }

            // Clean date fields - remove time portion if present
            $value_date = preg_replace('/\s.+$/', '', $value_date);
            $transaction_date = preg_replace('/\s.+$/', '', $transaction_date);
            $transaction_posted_date = preg_replace('/\s.+$/', '', $transaction_posted_date);

            if (!$stmt->execute()) {
                $is_error = true;
                $_SESSION['message'] = "❌ Insert failed on row " . ($index + 1) . ": " . $stmt->error;
                error_log("Failed to insert into $target_table: " . $stmt->error . " | Row data: " . json_encode($row)); 
                break; 
            }
            $imported_rows++;
        }
        
        $stmt->close();

        // Finalize transaction and redirect
        if ($is_error) {
            $mysqli->rollback();
            $_SESSION['message'] = $_SESSION['message'] ?? "❌ Import failed! Rolled back changes.";
        } else {
            $mysqli->commit();
            $message = "✅ **Success!** Imported $imported_rows transaction records.";
            if ($skipped_rows > 0) {
                $message .= " Skipped $skipped_rows rows (missing data).";
            }
            $_SESSION['message'] = $message;
        }

    } catch (\Exception $e) {
        if (isset($mysqli)) {
            $mysqli->rollback();
        }
        $_SESSION['message'] = "❌ An unexpected error occurred: " . $e->getMessage();
        error_log("Excel import error: " . $e->getMessage());
    }

    // Clear output buffer before redirect
    ob_end_clean();
    header('Location: bankstatement.php');
    exit;
} else {
    header('Location: bankstatement.php');
    exit;
}

/**
 * Convert Excel serial date to MySQL date format
 */
function excelToDate($excelDate) {
    // If it's already a string date, return as is
    if (preg_match('/^\d{4}-\d{2}-\d{2}/', $excelDate)) {
        return preg_replace('/\s.+$/', '', $excelDate); // Remove time portion
    }
    
    // If it's an Excel serial number
    if (is_numeric($excelDate)) {
        // Excel date (number of days since 1900-01-01)
        $unixTimestamp = ($excelDate - 25569) * 86400;
        return date('Y-m-d', $unixTimestamp);
    }
    
    // Try to parse other date formats
    $timestamp = strtotime($excelDate);
    if ($timestamp !== false) {
        return date('Y-m-d', $timestamp);
    }
    
    // Return original if cannot parse
    return $excelDate;
}
?>