<?php
// paypal_test_upload.php
session_start();
error_reporting(E_ALL);
ini_set('display_errors', 1);

require 'db.php';
$target_table = 'paypal_transactions';

// Check if SimpleXLSX is available
if (!class_exists('Shuchkin\SimpleXLSX')) {
    require_once __DIR__ . '/simplexlsx-master/src/SimpleXLSX.php';
}
use Shuchkin\SimpleXLSX;

// Check database connection
if (!isset($mysqli) || $mysqli->connect_error) {
    $_SESSION['message'] = "❌ FATAL Error: Database Connection Failed. Check db.php.";
    $_SESSION['message_type'] = 'error';
    header('Location: ' . $_SERVER['PHP_SELF']);
    exit;
}

// Ensure table exists (same as before)
$create_table_sql = "
CREATE TABLE IF NOT EXISTS `paypal_transactions` (
    `id` INT NOT NULL AUTO_INCREMENT,
    `date` DATE NULL,
    `time` TIME NULL,
    `timezone` VARCHAR(50) NULL,
    `name` VARCHAR(255) NULL,
    `type` VARCHAR(100) NULL,
    `status` VARCHAR(50) NULL,
    `currency` VARCHAR(10) NULL,
    `gross` DECIMAL(15,2) DEFAULT 0.00,
    `fee` DECIMAL(15,2) DEFAULT 0.00,
    `net` DECIMAL(15,2) DEFAULT 0.00,
    `from_email` VARCHAR(255) NULL,
    `to_email` VARCHAR(255) NULL,
    `transaction_id` VARCHAR(100) NULL,
    `shipping_address` TEXT NULL,
    `address_status` VARCHAR(50) NULL,
    `item_title` TEXT NULL,
    `item_id` VARCHAR(100) NULL,
    `shipping_amount` DECIMAL(15,2) DEFAULT 0.00,
    `insurance_amount` DECIMAL(15,2) DEFAULT 0.00,
    `sales_tax` DECIMAL(15,2) DEFAULT 0.00,
    `option1_name` VARCHAR(255) NULL,
    `option1_value` VARCHAR(255) NULL,
    `option2_name` VARCHAR(255) NULL,
    `option2_value` VARCHAR(255) NULL,
    `reference_txn_id` VARCHAR(100) NULL,
    `invoice_number` VARCHAR(100) NULL,
    `custom_number` VARCHAR(100) NULL,
    `quantity` INT DEFAULT 1,
    `receipt_id` VARCHAR(100) NULL,
    `balance` DECIMAL(15,2) DEFAULT 0.00,
    `address_line_1` VARCHAR(255) NULL,
    `address_line_2` VARCHAR(255) NULL,
    `city` VARCHAR(100) NULL,
    `state` VARCHAR(100) NULL,
    `zip_code` VARCHAR(20) NULL,
    `country` VARCHAR(100) NULL,
    `contact_phone_number` VARCHAR(50) NULL,
    `subject` TEXT NULL,
    `note` TEXT NULL,
    `country_code` VARCHAR(10) NULL,
    `balance_impact` VARCHAR(50) NULL,
    `imported_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `unique_transaction` (`transaction_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
";

if (!$mysqli->query($create_table_sql)) {
    $_SESSION['message'] = "❌ Error creating table: " . $mysqli->error;
    $_SESSION['message_type'] = 'error';
}

// Handle file upload and import
if (isset($_POST['save_excel_data']) && isset($_FILES['import_file'])) {
    $file = $_FILES['import_file'];
    
    // Check for upload errors
    if ($file['error'] !== UPLOAD_ERR_OK) {
        $upload_errors = [
            UPLOAD_ERR_INI_SIZE => 'File size exceeds server limit.',
            UPLOAD_ERR_FORM_SIZE => 'File size exceeds form limit.',
            UPLOAD_ERR_PARTIAL => 'File was only partially uploaded.',
            UPLOAD_ERR_NO_FILE => 'No file was uploaded.',
            UPLOAD_ERR_NO_TMP_DIR => 'Missing temporary folder.',
            UPLOAD_ERR_CANT_WRITE => 'Failed to write file to disk.',
            UPLOAD_ERR_EXTENSION => 'A PHP extension stopped the file upload.',
        ];
        $_SESSION['message'] = "❌ Upload error: " . ($upload_errors[$file['error']] ?? 'Unknown error');
        $_SESSION['message_type'] = 'error';
        header('Location: ' . $_SERVER['PHP_SELF']);
        exit;
    }
    
    // Validate file type by extension (simpler method for Windows)
    $allowed_extensions = ['xlsx', 'xls', 'csv'];
    $file_extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    
    if (!in_array($file_extension, $allowed_extensions)) {
        $_SESSION['message'] = "❌ Invalid file type. Please upload Excel (.xlsx, .xls) or CSV files only.";
        $_SESSION['message_type'] = 'error';
        header('Location: ' . $_SERVER['PHP_SELF']);
        exit;
    }
    
    // Check file size (max 10MB)
    if ($file['size'] > 10 * 1024 * 1024) {
        $_SESSION['message'] = "❌ File is too large. Maximum size is 10MB.";
        $_SESSION['message_type'] = 'error';
        header('Location: ' . $_SERVER['PHP_SELF']);
        exit;
    }
    
    // Move uploaded file
    $upload_dir = __DIR__ . '/uploads/';
    if (!is_dir($upload_dir)) {
        mkdir($upload_dir, 0755, true);
    }
    
    $temp_file = $upload_dir . basename($file['name']);
    
    if (!move_uploaded_file($file['tmp_name'], $temp_file)) {
        $_SESSION['message'] = "❌ Failed to move uploaded file.";
        $_SESSION['message_type'] = 'error';
        header('Location: ' . $_SERVER['PHP_SELF']);
        exit;
    }
    
    try {
        // Parse file based on extension
        if ($file_extension === 'csv') {
            // Handle CSV
            $handle = fopen($temp_file, 'r');
            if (!$handle) {
                throw new Exception("Cannot open CSV file");
            }
            
            $rows = [];
            $header = fgetcsv($handle); // Get header row
            
            while (($data = fgetcsv($handle)) !== false) {
                $rows[] = $data;
            }
            fclose($handle);
            
            // Create a mock xlsx object for consistency
            $xlsx = new stdClass();
            $xlsx->rows = array_merge([$header], $rows);
            
            // Simple function to mimic SimpleXLSX rows() method
            $xlsx->rows = function() use ($xlsx) {
                return $xlsx->rows;
            };
        } else {
            // Handle Excel using SimpleXLSX
            if ($file_extension === 'xls' || $file_extension === 'xlsx') {
                $xlsx = SimpleXLSX::parse($temp_file);
                if (!$xlsx) {
                    throw new Exception("Failed to parse Excel file. Make sure it's a valid Excel file.");
                }
            } else {
                throw new Exception("Unsupported file format");
            }
        }
        
        // Get rows
        $rows = is_callable($xlsx->rows) ? $xlsx->rows() : $xlsx->rows();
        
        if (empty($rows) || count($rows) < 2) {
            throw new Exception("Excel file is empty or has no data rows.");
        }
        
        // Get header row (first row)
        $header = $rows[0];
        
        // Map PayPal columns to database columns
        $column_mapping = [
            'Date' => 'date',
            'Time' => 'time',
            'Timezone' => 'timezone',
            'Name' => 'name',
            'Type' => 'type',
            'Status' => 'status',
            'Currency' => 'currency',
            'Gross' => 'gross',
            'Fee' => 'fee',
            'Net' => 'net',
            'From Email Address' => 'from_email',
            'To Email Address' => 'to_email',
            'Transaction ID' => 'transaction_id',
            'Shipping Address' => 'shipping_address',
            'Address Status' => 'address_status',
            'Item Title' => 'item_title',
            'Item ID' => 'item_id',
            'Postage and Packaging Amount' => 'shipping_amount',
            'Insurance Amount' => 'insurance_amount',
            'VAT' => 'sales_tax',
            'Option 1 Name' => 'option1_name',
            'Option 1 Value' => 'option1_value',
            'Option 2 Name' => 'option2_name',
            'Option 2 Value' => 'option2_value',
            'Reference Txn ID' => 'reference_txn_id',
            'Invoice Number' => 'invoice_number',
            'Custom Number' => 'custom_number',
            'Quantity' => 'quantity',
            'Receipt ID' => 'receipt_id',
            'Balance' => 'balance',
            'Address Line 1' => 'address_line_1',
            'Address Line 2/District/Neighbourhood' => 'address_line_2',
            'Town/City' => 'city',
            'County' => 'state',
            'Postcode' => 'zip_code',
            'Country' => 'country',
            'Contact Phone Number' => 'contact_phone_number',
            'Subject' => 'subject',
            'Note' => 'note',
            'Country Code' => 'country_code',
            'Balance Impact' => 'balance_impact'
        ];
        
        // Prepare insert statement
        $columns = array_values($column_mapping);
        $placeholders = implode(',', array_fill(0, count($columns), '?'));
        $sql = "INSERT INTO $target_table (" . implode(',', $columns) . ") 
                VALUES ($placeholders) 
                ON DUPLICATE KEY UPDATE 
                date=VALUES(date), 
                gross=VALUES(gross), 
                fee=VALUES(fee), 
                net=VALUES(net), 
                status=VALUES(status)";
        
        $stmt = $mysqli->prepare($sql);
        if (!$stmt) {
            throw new Exception("Prepare failed: " . $mysqli->error);
        }
        
        $success_count = 0;
        $skip_count = 0;
        $error_count = 0;
        $errors = [];
        
        // Process each row (skip header)
        for ($i = 1; $i < count($rows); $i++) {
            $row = $rows[$i];
            
            // Skip empty rows
            if (empty(array_filter($row, function($val) { 
                return $val !== null && $val !== '' && $val !== false; 
            }))) {
                continue;
            }
            
            // Map data to columns
            $data = [];
            foreach ($column_mapping as $excel_col => $db_col) {
                $index = array_search($excel_col, $header);
                $value = ($index !== false && isset($row[$index])) ? trim($row[$index]) : null;
                
                // Clean and format data
                if ($value === null || $value === '') {
                    $data[] = null;
                } elseif (in_array($db_col, ['gross', 'fee', 'net', 'shipping_amount', 'insurance_amount', 'sales_tax', 'balance'])) {
                    // Handle numeric values - remove currency symbols and commas
                    $clean_value = str_replace(['$', '€', '£', ',', ' '], '', $value);
                    $data[] = is_numeric($clean_value) ? (float)$clean_value : 0.00;
                } elseif ($db_col === 'date' && !empty($value)) {
                    // Parse date - handle different formats
                    $date_formats = [
                        'Y-m-d H:i:s',
                        'Y-m-d',
                        'd/m/Y H:i:s',
                        'd/m/Y',
                        'm/d/Y H:i:s',
                        'm/d/Y',
                        'd-m-Y H:i:s',
                        'd-m-Y',
                        'm-d-Y H:i:s',
                        'm-d-Y'
                    ];
                    
                    $parsed_date = null;
                    foreach ($date_formats as $format) {
                        $date = DateTime::createFromFormat($format, $value);
                        if ($date) {
                            $parsed_date = $date->format('Y-m-d');
                            break;
                        }
                    }
                    $data[] = $parsed_date;
                } elseif ($db_col === 'time' && !empty($value)) {
                    // Parse time
                    if (preg_match('/(\d{1,2}):(\d{1,2})(?::(\d{1,2}))?/', $value, $matches)) {
                        $hour = (int)$matches[1];
                        $minute = (int)$matches[2];
                        $second = isset($matches[3]) ? (int)$matches[3] : 0;
                        
                        // Handle AM/PM
                        if (stripos($value, 'PM') !== false && $hour < 12) {
                            $hour += 12;
                        } elseif (stripos($value, 'AM') !== false && $hour == 12) {
                            $hour = 0;
                        }
                        
                        $data[] = sprintf('%02d:%02d:%02d', $hour, $minute, $second);
                    } else {
                        $data[] = null;
                    }
                } elseif ($db_col === 'quantity' && !empty($value)) {
                    $data[] = (int)$value;
                } else {
                    $data[] = $value;
                }
            }
            
            // Check if transaction ID is required
            $transaction_id_index = array_search('transaction_id', $columns);
            if (empty($data[$transaction_id_index])) {
                $skip_count++;
                continue;
            }
            
            // Bind parameters dynamically
            $types = '';
            foreach ($data as $value) {
                if (is_float($value)) {
                    $types .= 'd'; // double
                } elseif (is_int($value)) {
                    $types .= 'i'; // integer
                } else {
                    $types .= 's'; // string
                }
            }
            
            $stmt->bind_param($types, ...$data);
            
            if ($stmt->execute()) {
                $success_count++;
            } else {
                $error_count++;
                if ($error_count <= 5) { // Limit error messages
                    $errors[] = "Row $i: " . $stmt->error;
                }
            }
        }
        
        $stmt->close();
        
        // Clean up
        if (file_exists($temp_file)) {
            unlink($temp_file);
        }
        
        // Set session message
        $message = "✅ Import completed: ";
        $message .= "$success_count transactions imported successfully. ";
        
        if ($skip_count > 0) {
            $message .= "$skip_count rows skipped. ";
        }
        
        if ($error_count > 0) {
            $message .= "$error_count errors occurred. ";
            if (count($errors) > 0) {
                $message .= "First error: " . $errors[0];
            }
        }
        
        $_SESSION['message'] = $message;
        $_SESSION['message_type'] = 'success';
        
    } catch (Exception $e) {
        // Clean up temp file
        if (file_exists($temp_file)) {
            unlink($temp_file);
        }
        
        $_SESSION['message'] = "❌ Import failed: " . $e->getMessage();
        $_SESSION['message_type'] = 'error';
    }
    
    header('Location: ' . $_SERVER['PHP_SELF']);
    exit;
}

// Fetch existing PayPal transactions
$transactions = [];
$total_transactions = 0;
try {
    $result = $mysqli->query("SELECT id, date, name, type, status, gross, fee, net, from_email, transaction_id FROM $target_table ORDER BY id DESC LIMIT 100");
    if ($result) {
        $transactions = $result->fetch_all(MYSQLI_ASSOC);
        $total_transactions = count($transactions);
    }
} catch (Exception $e) {
    error_log("Error fetching transactions: " . $e->getMessage());
}

require 'header.php';
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>PayPal Transactions Import</title>
    <style>
        body { 
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; 
            max-width: 1500px; 
            margin: 0 auto;
            padding: 20px; 
            background: #f8f9fa; 
            color: #333;
        }
        .container { 
            background: white; 
            padding: 25px; 
            border-radius: 8px; 
            box-shadow: 0 1px 5px rgba(0,0,0,0.1); 
        }
        .main-title {
            font-size: 22px;
            font-weight: 600;
            color: #2c3e50;
            margin-bottom: 25px;
            padding-bottom: 10px;
            border-bottom: 2px solid #e9ecef;
        }
        .upload-form { 
            border: 2px dashed #6c757d; 
            padding: 25px; 
            text-align: center; 
            margin-bottom: 25px; 
            border-radius: 8px;
            background: #f8f9fa;
        }
        .upload-title {
            font-size: 18px;
            font-weight: 500;
            color: #495057;
            margin-bottom: 10px;
        }
        .upload-subtitle {
            font-size: 14px;
            color: #6c757d;
            margin-bottom: 20px;
        }
        .btn { 
            background: #007bff; 
            color: white; 
            padding: 10px 20px; 
            border: none; 
            border-radius: 5px; 
            cursor: pointer; 
            font-size: 14px; 
            font-weight: 500;
            transition: background 0.3s ease;
        }
        .btn:hover { 
            background: #0056b3; 
            transform: translateY(-1px);
        }
        .message { 
            padding: 12px 15px; 
            margin: 15px 0; 
            border-radius: 5px; 
            font-weight: 500; 
            font-size: 14px;
        }
        .success { 
            background: #d4edda; 
            color: #155724; 
            border: 1px solid #c3e6cb; 
        }
        .error { 
            background: #f8d7da; 
            color: #721c24; 
            border: 1px solid #f5c6cb; 
        }
        .section-title { 
            background: #6c757d; 
            color: white; 
            padding: 12px 15px; 
            border-radius: 5px; 
            margin: 5px 0 15px 0; 
            font-size: 16px;
            font-weight: 500;
        }
        .stats { 
            background: #28a745; 
            color: white; 
            padding: 8px 12px; 
            border-radius: 5px; 
            margin-bottom: 15px; 
            display: inline-block;
            font-size: 14px;
            font-weight: 500;
        }
        .file-input {
            margin: 15px 0;
            padding: 8px;
            border: 1px solid #ced4da;
            border-radius: 4px;
            width: 100%;
            max-width: 300px;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1 class="main-title">📊 PayPal Transactions Import</h1>
        
        <?php if (isset($_SESSION['message'])): ?>
            <div class="message <?php echo $_SESSION['message_type'] ?? 'error'; ?>">
                <?php 
                echo $_SESSION['message']; 
                unset($_SESSION['message']);
                unset($_SESSION['message_type']);
                ?>
            </div>
        <?php endif; ?>

        <center>
            <div class="upload-form">
                <h3 class="upload-title">Upload PayPal Transactions Excel File</h3>
                <p class="upload-subtitle">Supported formats: .xlsx, .xls, .csv</p>
                
                <form action="" method="POST" enctype="multipart/form-data">
                    <input type="file" name="import_file" accept=".xlsx,.xls,.csv" required class="file-input">
                    <br>
                    <button type="submit" name="save_excel_data" class="btn">📤 Upload & Import</button>
                </form>
            </div>
        </center>

        <div class="section-title">
            📋 Existing PayPal Transactions (<?php echo $total_transactions; ?> records)
        </div>

        <?php if ($total_transactions > 0): ?>
            <div class="stats">
                Total Transactions: <?php echo $total_transactions; ?>
            </div>
            <div style="margin-top: 10px;">
                <a href="paypalstatement.php" class="btn" style="background: #17a2b8;">
                    📋 View PayPal List
                </a>
            </div>
        <?php endif; ?>
    </div>
</body>
</html>