<?php
session_start();
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Start output buffering (good practice for header redirects)
ob_start();

// Ensure 'db.php' contains your working mysqli connection
require 'db.php';

// Define the target table
// NOTE: I am using 'paypal_transaction' as you defined it below the code block.
$target_table = 'paypal_transaction';

// Include Excel library
require_once __DIR__ . '/simplexlsx-master/src/SimpleXLSX.php';
use Shuchkin\SimpleXLSX;

// Check if the form submission key is correct (using your key)
if (isset($_POST['save_excel_data'])) {
    
    // Check for file upload
    if (!isset($_FILES['import_file']) || $_FILES['import_file']['error'] !== UPLOAD_ERR_OK) {
        $upload_errors = [
            UPLOAD_ERR_NO_FILE => 'Please select a file to upload.',
            // ... include other errors as needed ...
        ];
        $error_code = $_FILES['import_file']['error'] ?? UPLOAD_ERR_NO_FILE;
        $_SESSION['message'] = "❌ File upload error: " . ($upload_errors[$error_code] ?? 'Unknown error');
        $_SESSION['message_type'] = 'error';
        header('Location: paypalstatement.php');
        exit;
    }

    $fileName = $_FILES['import_file']['name'];
    $inputFileNamePath = $_FILES['import_file']['tmp_name'];
    
    try {
        // Load the spreadsheet
        $xlsx = SimpleXLSX::parse($inputFileNamePath);
        if (!$xlsx) {
            throw new Exception('Error reading Excel file: ' . SimpleXLSX::parseError());
        }
        
        $data = $xlsx->rows();
        
        if (empty($data)) {
            throw new Exception('The Excel file is empty.');
        }

        $imported_rows = 0;
        $skipped_rows = 0;
        
        // Start transaction
        $mysqli->begin_transaction();
        
        // **********************************************
        // ******* CRITICAL FIX: 6 COLUMN INSERT *******
        // The table only has name, type, status, currency, gross, fee.
        $sql = "INSERT INTO $target_table (name, type, status, currency, gross, fee) VALUES (?, ?, ?, ?, ?, ?)";
        // **********************************************
        
        $stmt = $mysqli->prepare($sql);
        
        if (!$stmt) {
            throw new Exception("Database prepare error: Check table structure and SQL column list. MySQL Error: " . $mysqli->error);
        }
        
        // **********************************************
        // ******* CRITICAL FIX: 6 PARAMETER BIND *******
        // Bind 4 strings and 2 decimals (s s s s d d)
        $stmt->bind_param("ssssdd", $name, $type, $status, $currency, $gross, $fee);
        // **********************************************
        
        // Process rows (skip header if needed)
        $start_row = 0;
        // Check for header row based on the first column name
        if (isset($data[0][0]) && strtolower(trim($data[0][0])) === 'name') {
            $start_row = 1; // Skip header row
        }
        
        for ($i = $start_row; $i < count($data); $i++) {
            $row = $data[$i];
            
            // Skip empty rows
            if (empty($row) || (count($row) === 1 && empty(trim($row[0] ?? '')))) {
                $skipped_rows++;
                continue;
            }
            
            // **********************************************
            // ******* CRITICAL FIX: IGNORE 7TH COLUMN (NET) *******
            // We only extract the first 6 columns (index 0 to 5)
            $name     = $row[0] ?? ''; // Name (Index 0)
            $type     = $row[1] ?? ''; // Type (Index 1)
            $status   = $row[2] ?? ''; // Status (Index 2)
            $currency = $row[3] ?? ''; // Currency (Index 3)
            $gross    = $row[4] ?? 0;  // Gross (Index 4)
            $fee      = $row[5] ?? 0;  // Fee (Index 5)
            // $row[6] (Net) is ignored
            // **********************************************
            
            // Clean data
            $name = trim($name);
            $type = trim($type);
            $status = trim($status);
            $currency = trim($currency);
            
            // Skip if no name
            if (empty($name)) {
                $skipped_rows++;
                continue;
            }
            
            // Convert to numbers (using float conversion for binding as double 'd')
            $gross = (float) $gross;
            $fee = (float) $fee;
            
            // Execute insert
            if ($stmt->execute()) {
                $imported_rows++;
            } else {
                // Enhanced error reporting
                $mysqli->rollback();
                $stmt->close();
                throw new Exception("Insert failed on row " . ($i + 1) . ". MySQL Error: " . $stmt->error . ". Data: " . htmlspecialchars(json_encode(array_slice($row, 0, 6))));
            }
        }
        
        $stmt->close();
        $mysqli->commit();
        
        $_SESSION['message'] = "✅ Success! Imported **$imported_rows** records." . ($skipped_rows > 0 ? " Skipped **$skipped_rows** rows." : "");
        $_SESSION['message_type'] = 'success';
        
    } catch (Exception $e) {
        if (isset($mysqli)) {
            // Rollback if transaction was started
            $mysqli->rollback();
        }
        $_SESSION['message'] = "❌ Error: **" . htmlspecialchars($e->getMessage()) . "**";
        $_SESSION['message_type'] = 'error';
    }
    
    // Redirect to the display page
    header('Location: paypalstatement.php');
    exit;
    
} else {
    // If accessed directly without form submission, redirect
    header('Location: paypalstatement.php');
    exit;
}
?>

<?php ob_end_flush(); ?>