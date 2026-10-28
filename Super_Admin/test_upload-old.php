<?php
session_start();
error_reporting(E_ALL);
ini_set('display_errors', 1);

require 'db.php';

// Define the target table
$target_table = 'paypal_transactions';

// Include Excel library
require_once __DIR__ . '/simplexlsx-master/src/SimpleXLSX.php';
use Shuchkin\SimpleXLSX;

// Process form submission
if (isset($_POST['save_excel_data']) && isset($_FILES['import_file'])) {
    
    // File upload validation
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
        $_SESSION['message'] = "❌ Upload error: " . ($upload_errors[$_FILES['import_file']['error']] ?? 'Unknown error');
        $_SESSION['message_type'] = 'error';
    } else {
        $fileName = $_FILES['import_file']['name'];
        $inputFileNamePath = $_FILES['import_file']['tmp_name'];
        $allowed_ext = ['xlsx', 'xls', 'csv']; 
        $file_ext = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));

        if (!in_array($file_ext, $allowed_ext)) {
            $_SESSION['message'] = "❌ Invalid file type. Please upload .xlsx, .xls, or .csv files only.";
            $_SESSION['message_type'] = 'error';
        } elseif (!file_exists($inputFileNamePath)) {
            $_SESSION['message'] = "❌ File not found.";
            $_SESSION['message_type'] = 'error';
        } else {
            try {
                // Parse Excel file
                $xlsx = SimpleXLSX::parse($inputFileNamePath);
                if (!$xlsx) {
                    throw new Exception('Excel parsing error: ' . SimpleXLSX::parseError());
                }
                
                $data = $xlsx->rows(); 
                
                if (empty($data)) {
                    throw new Exception("The Excel file is empty.");
                }

                $imported_rows = 0;
                $skipped_rows = 0;
                
                // Start transaction
                $mysqli->begin_transaction();
                
                // First, let's check the actual structure of the Excel file
                echo "<pre>Excel File Structure:\n";
                echo "Number of rows: " . count($data) . "\n";
                if (count($data) > 0) {
                    echo "First row (headers):\n";
                    print_r($data[0]);
                    if (count($data) > 1) {
                        echo "Second row (sample data):\n";
                        print_r($data[1]);
                    }
                }
                echo "</pre>";
                
                // Prepare INSERT statement for PayPal transactions - simplified version
                $sql = "INSERT INTO $target_table (
                            date, time, timezone, name, type, status, currency, 
                            gross, fee, net, from_email, to_email, transaction_id
                        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
                
                $stmt = $mysqli->prepare($sql);
                if (!$stmt) {
                    throw new Exception("Database error: " . $mysqli->error);
                }
                
                // Initialize variables for basic fields
                $date = $time = $timezone = $name = $type = $status = $currency = "";
                $gross = $fee = $net = 0;
                $from_email = $to_email = $transaction_id = "";
                
                // Bind parameters
                $stmt->bind_param(
                    "sssssssddssss", 
                    $date, $time, $timezone, $name, $type, $status, $currency, 
                    $gross, $fee, $net, $from_email, $to_email, $transaction_id
                );
                
                // Process rows (skip header if present)
                $start_row = 0;
                if (isset($data[0][0]) && (
                    strtolower($data[0][0]) === 'date' || 
                    strtolower($data[0][0]) === 'transaction date' ||
                    strtolower($data[0][0]) === 'time'
                )) {
                    $start_row = 1; // Skip header row
                }
                
                for ($i = $start_row; $i < count($data); $i++) {
                    $row = $data[$i];
                    
                    // Skip empty rows
                    if (empty($row) || (count($row) === 1 && empty($row[0]))) {
                        $skipped_rows++;
                        continue;
                    }
                    
                    // Debug: Show row data
                    echo "<pre>Processing row $i:\n";
                    print_r($row);
                    echo "</pre>";
                    
                    // Map Excel columns to PayPal transaction fields
                    // Adjust these indices based on your actual Excel file structure
                    $date                   = $row[0] ?? '';
                    $time                   = $row[1] ?? '';
                    $timezone               = $row[2] ?? '';
                    $name                   = $row[3] ?? '';
                    $type                   = $row[4] ?? '';
                    $status                 = $row[5] ?? '';
                    $currency               = $row[6] ?? '';
                    $gross                  = isset($row[7]) && is_numeric($row[7]) ? floatval($row[7]) : 0;
                    $fee                    = isset($row[8]) && is_numeric($row[8]) ? floatval($row[8]) : 0;
                    $net                    = isset($row[9]) && is_numeric($row[9]) ? floatval($row[9]) : 0;
                    $from_email             = $row[10] ?? '';
                    $to_email               = $row[11] ?? '';
                    $transaction_id         = $row[12] ?? '';
                    
                    // Clean data
                    $date = trim($date);
                    $time = trim($time);
                    $transaction_id = trim($transaction_id);
                    
                    // Validate required fields
                    if (empty($date) || empty($transaction_id)) {
                        $skipped_rows++;
                        continue;
                    }

                    // Execute insert
                    if ($stmt->execute()) {
                        $imported_rows++;
                    } else {
                        throw new Exception("Insert failed on row " . ($i + 1) . ": " . $stmt->error);
                    }
                }
                
                $stmt->close();
                $mysqli->commit();
                
                // Success message
                $message = "✅ Success! Imported $imported_rows PayPal transaction records.";
                if ($skipped_rows > 0) {
                    $message .= " Skipped $skipped_rows rows.";
                }
                $_SESSION['message'] = $message;
                $_SESSION['message_type'] = 'success';
                
            } catch (Exception $e) {
                if (isset($mysqli)) {
                    $mysqli->rollback();
                }
                $_SESSION['message'] = "❌ Error: " . $e->getMessage();
                $_SESSION['message_type'] = 'error';
            }
        }
    }
    
    // Redirect to clear POST data
    header('Location: ' . $_SERVER['PHP_SELF']);
    exit;
}

// Fetch existing PayPal transactions to display
$transactions = [];
$total_transactions = 0;
try {
    $result = $mysqli->query("SELECT * FROM $target_table ORDER BY id DESC LIMIT 100");
    if ($result) {
        $transactions = $result->fetch_all(MYSQLI_ASSOC);
        $total_transactions = count($transactions);
    }
} catch (Exception $e) {
    error_log("Error fetching transactions: " . $e->getMessage());
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>PayPal Transactions Import</title>
    <style>
        body { font-family: Arial, sans-serif; max-width: 1400px; margin: 0 auto; padding: 20px; background: #f5f5f5; }
        .container { background: white; padding: 30px; border-radius: 10px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); }
        .upload-form { border: 2px dashed #007bff; padding: 30px; text-align: center; margin-bottom: 30px; }
        .btn { background: #007bff; color: white; padding: 12px 25px; border: none; border-radius: 5px; cursor: pointer; font-size: 16px; }
        .btn:hover { background: #0056b3; }
        .message { padding: 15px; margin: 20px 0; border-radius: 5px; font-weight: bold; }
        .success { background: #d4edda; color: #155724; border: 1px solid #c3e6cb; }
        .error { background: #f8d7da; color: #721c24; border: 1px solid #f5c6cb; }
        table { width: 100%; border-collapse: collapse; margin-top: 20px; font-size: 12px; }
        th, td { border: 1px solid #ddd; padding: 8px; text-align: left; }
        th { background-color: #f8f9fa; font-weight: bold; }
        tr:nth-child(even) { background-color: #f8f9fa; }
        .section-title { background: #007bff; color: white; padding: 15px; border-radius: 5px; margin: 30px 0 15px 0; }
        .stats { background: #28a745; color: white; padding: 10px 15px; border-radius: 5px; margin-bottom: 20px; display: inline-block; }
        .scrollable-table { overflow-x: auto; }
        .debug-info { background: #fff3cd; border: 1px solid #ffeaa7; padding: 15px; margin: 10px 0; border-radius: 5px; }
    </style>
</head>
<body>
    <div class="container">
        <h1>📊 PayPal Transactions Import</h1>
        
        <?php if (isset($_SESSION['message'])): ?>
            <div class="message <?php echo $_SESSION['message_type'] ?? 'error'; ?>">
                <?php 
                echo $_SESSION['message']; 
                unset($_SESSION['message']);
                unset($_SESSION['message_type']);
                ?>
            </div>
        <?php endif; ?>

        <!-- Upload Form -->
        <div class="upload-form">
            <h3>Upload PayPal Transactions Excel File</h3>
            <p>Supported formats: .xlsx, .xls, .csv</p>
            
            <form action="" method="POST" enctype="multipart/form-data">
                <input type="file" name="import_file" accept=".xlsx,.xls,.csv" required style="margin: 20px 0;">
                <br>
                <button type="submit" name="save_excel_data" class="btn">📤 Upload & Import</button>
            </form>
        </div>

        <!-- Debug Information -->
        <?php if (isset($_POST['save_excel_data']) && isset($_FILES['import_file'])): ?>
            <div class="debug-info">
                <h4>🔧 Debug Information</h4>
                <p>This section shows the structure of your Excel file to help with mapping.</p>
            </div>
        <?php endif; ?>

        <!-- Existing Transactions -->
        <div class="section-title">
            <h3 style="margin: 0; color: white;">📋 Existing PayPal Transactions (<?php echo $total_transactions; ?> records)</h3>
        </div>

        <?php if ($total_transactions > 0): ?>
            <div class="stats">
                Total Transactions: <?php echo $total_transactions; ?>
            </div>
            
            <div class="scrollable-table">
                <table>
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Date</th>
                            <th>Name</th>
                            <th>Type</th>
                            <th>Status</th>
                            <th>Gross</th>
                            <th>Fee</th>
                            <th>Net</th>
                            <th>From Email</th>
                            <th>Transaction ID</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($transactions as $transaction): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($transaction['id'] ?? ''); ?></td>
                            <td><?php echo htmlspecialchars($transaction['date'] ?? ''); ?></td>
                            <td><?php echo htmlspecialchars($transaction['name'] ?? ''); ?></td>
                            <td><?php echo htmlspecialchars($transaction['type'] ?? ''); ?></td>
                            <td><?php echo htmlspecialchars($transaction['status'] ?? ''); ?></td>
                            <td><?php echo htmlspecialchars($transaction['gross'] ?? ''); ?></td>
                            <td><?php echo htmlspecialchars($transaction['fee'] ?? ''); ?></td>
                            <td><?php echo htmlspecialchars($transaction['net'] ?? ''); ?></td>
                            <td><?php echo htmlspecialchars($transaction['from_email'] ?? ''); ?></td>
                            <td><?php echo htmlspecialchars($transaction['transaction_id'] ?? ''); ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php else: ?>
            <div style="text-align: center; padding: 40px; background: #f8f9fa; border-radius: 5px;">
                <p style="font-size: 18px; color: #666;">No PayPal transactions found in database.</p>
                <p>Upload an Excel file to import transaction data.</p>
            </div>
        <?php endif; ?>

        <!-- Sample Format Guide -->
        <div style="background: #f8f9fa; padding: 20px; border-radius: 5px; margin-top: 30px;">
            <h4>📝 Expected Excel Format (Basic columns):</h4>
            <p><strong>Columns should be in this order:</strong></p>
            <table border="1" cellpadding="8" style="width: 100%; border-collapse: collapse; font-size: 12px;">
                <tr style="background: #e9ecef;">
                    <th>Date</th>
                    <th>Time</th>
                    <th>Timezone</th>
                    <th>Name</th>
                    <th>Type</th>
                    <th>Status</th>
                    <th>Currency</th>
                    <th>Gross</th>
                    <th>Fee</th>
                    <th>Net</th>
                    <th>From Email</th>
                    <th>To Email</th>
                    <th>Transaction ID</th>
                </tr>
                <tr>
                    <td>2023-12-01</td>
                    <td>14:30:00</td>
                    <td>PST</td>
                    <td>John Doe</td>
                    <td>Payment Received</td>
                    <td>Completed</td>
                    <td>USD</td>
                    <td>100.00</td>
                    <td>3.20</td>
                    <td>96.80</td>
                    <td>buyer@example.com</td>
                    <td>seller@example.com</td>
                    <td>5LY58563T3694724Y</td>
                </tr>
            </table>
            <p style="margin-top: 10px; font-size: 12px; color: #666;">
                <strong>Note:</strong> The debug section above will show your actual Excel file structure. 
                You may need to adjust the column mapping in the code to match your file.
            </p>
        </div>
    </div>
</body>
</html>