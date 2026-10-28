<?php
session_start();
error_reporting(E_ALL);
ini_set('display_errors', 1);

require 'db.php'; // Requires a working $mysqli connection object

// Define the target table
$target_table = 'paypal_transactions';

// Include Excel library
// NOTE: Ensure your folder structure is correct: /simplexlsx-master/src/SimpleXLSX.php
require_once __DIR__ . '/simplexlsx-master/src/SimpleXLSX.php';
use Shuchkin\SimpleXLSX;

// Check for successful database connection early
if (!isset($mysqli) || $mysqli->connect_error) {
    $_SESSION['message'] = "❌ FATAL Error: Database Connection Failed. Check db.php.";
    $_SESSION['message_type'] = 'error';
    header('Location: ' . $_SERVER['PHP_SELF']);
    exit;
}

// Process form submission
if (isset($_POST['save_excel_data']) && isset($_FILES['import_file'])) {
    
    // File upload validation
    if ($_FILES['import_file']['error'] !== UPLOAD_ERR_OK) {
        $upload_errors = [
            UPLOAD_ERR_NO_FILE => 'No file was uploaded.',
            UPLOAD_ERR_INI_SIZE => 'File size exceeds server limit.',
            // Add other upload errors as needed
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
                
                // Prepare INSERT statement for all 41 PayPal transaction columns
                $sql = "INSERT INTO $target_table (
                            date, time, timezone, name, type, status, currency, gross, fee, net, 
                            from_email, to_email, transaction_id, shipping_address, address_status, 
                            item_title, item_id, shipping_amount, insurance_amount, sales_tax, 
                            option1_name, option1_value, option2_name, option2_value, reference_txn_id, 
                            invoice_number, custom_number, quantity, receipt_id, balance, 
                            address_line_1, address_line_2, city, state, zip_code, country, 
                            contact_phone_number, subject, note, country_code, balance_impact
                        ) VALUES (
                            ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 
                            ?, ?, ?, ?, ?, 
                            ?, ?, ?, ?, ?, 
                            ?, ?, ?, ?, ?, 
                            ?, ?, ?, ?, ?, 
                            ?, ?, ?, ?, ?, ?, 
                            ?, ?, ?, ?, ?
                        )";
                
                $stmt = $mysqli->prepare($sql);
                if (!$stmt) {
                    throw new Exception("Database prepare error: **" . $mysqli->error . "** (Check table structure and column names.)");
                }
                
                // Initialize variables for all 41 fields
                $date = $time = $timezone = $name = $type = $status = $currency = "";
                $gross = $fee = $net = 0.00;
                $from_email = $to_email = $transaction_id = $shipping_address = $address_status = "";
                $item_title = $item_id = $shipping_amount = $insurance_amount = $sales_tax = 0.00; // These amounts are decimal/double
                $option1_name = $option1_value = $option2_name = $option2_value = $reference_txn_id = "";
                $invoice_number = $custom_number = $quantity = $receipt_id = "";
                $balance = 0.00;
                $address_line_1 = $address_line_2 = $city = $state = $zip_code = $country = "";
                $contact_phone_number = $subject = $note = $country_code = $balance_impact = "";
                
                // *************************************************************************************************
                // ******* CRITICAL FIX: Corrected bind_param string (41 characters to match 41 columns) *******
                // Correct string is: sssssssdddssssssdsssssssisdsssssssssss
                // *************************************************************************************************
                $stmt->bind_param(
                    "sssssssdddsssssssdddsssssssisdsssssssssss", // 41 characters: 37 's', 3 'd', 1 'i' for quantity
                    $date, $time, $timezone, $name, $type, $status, $currency, $gross, $fee, $net,
                    $from_email, $to_email, $transaction_id, $shipping_address, $address_status,
                    $item_title, $item_id, $shipping_amount, $insurance_amount, $sales_tax,
                    $option1_name, $option1_value, $option2_name, $option2_value, $reference_txn_id,
                    $invoice_number, $custom_number, $quantity, $receipt_id, $balance,
                    $address_line_1, $address_line_2, $city, $state, $zip_code, $country,
                    $contact_phone_number, $subject, $note, $country_code, $balance_impact
                );
                
                // Process rows (skip header if present)
                $start_row = 0;
                if (isset($data[0][0]) && (strtolower(trim($data[0][0])) === 'date' || strtolower(trim($data[0][0])) === 'transaction date')) {
                    $start_row = 1; // Skip header row
                }
                
                for ($i = $start_row; $i < count($data); $i++) {
                    $row = $data[$i];
                    
                    // Skip empty rows and ensure enough columns exist (at least 41 for safety)
                    if (empty($row) || count($row) < 41 || (count($row) === 1 && empty(trim($row[0] ?? '')))) {
                        $skipped_rows++;
                        continue;
                    }

                    // --- MAPPING (Must match the order of the SQL and bind_param) ---
                    $date                  = $row[0] ?? '';
                    $time                  = $row[1] ?? '';
                    $timezone              = $row[2] ?? '';
                    $name                  = $row[3] ?? '';
                    $type                  = $row[4] ?? '';
                    $status                = $row[5] ?? '';
                    $currency              = $row[6] ?? '';
                    $gross                 = floatval($row[7] ?? 0);
                    $fee                   = floatval($row[8] ?? 0);
                    $net                   = floatval($row[9] ?? 0);
                    $from_email            = $row[10] ?? '';
                    $to_email              = $row[11] ?? '';
                    $transaction_id        = $row[12] ?? '';
                    $shipping_address      = $row[13] ?? '';
                    $address_status        = $row[14] ?? '';
                    $item_title            = $row[15] ?? '';
                    $item_id               = $row[16] ?? '';
                    $shipping_amount       = floatval($row[17] ?? 0);
                    $insurance_amount      = floatval($row[18] ?? 0);
                    $sales_tax             = floatval($row[19] ?? 0);
                    $option1_name          = $row[20] ?? '';
                    $option1_value         = $row[21] ?? '';
                    $option2_name          = $row[22] ?? '';
                    $option2_value         = $row[23] ?? '';
                    $reference_txn_id      = $row[24] ?? '';
                    $invoice_number        = $row[25] ?? '';
                    $custom_number         = $row[26] ?? '';
                    $quantity              = intval($row[27] ?? 0); // Bound as 'i'
                    $receipt_id            = $row[28] ?? '';
                    $balance               = floatval($row[29] ?? 0);
                    $address_line_1        = $row[30] ?? '';
                    $address_line_2        = $row[31] ?? '';
                    $city                  = $row[32] ?? '';
                    $state                 = $row[33] ?? '';
                    $zip_code              = $row[34] ?? '';
                    $country               = $row[35] ?? '';
                    $contact_phone_number  = $row[36] ?? '';
                    $subject               = $row[37] ?? '';
                    $note                  = $row[38] ?? '';
                    $country_code          = $row[39] ?? '';
                    $balance_impact        = $row[40] ?? '';
                    // -------------------------------------------------------------------
                    
                    // Validate required fields
                    if (empty(trim($transaction_id))) {
                        $skipped_rows++;
                        continue;
                    }

                    // Execute insert
                    if ($stmt->execute()) {
                        $imported_rows++;
                    } else {
                        // Throw exception to trigger rollback and show specific MySQL error
                        throw new Exception("Insert failed on row " . ($i + 1) . ". MySQL Error: **" . $stmt->error . "**");
                    }
                }
                
                $stmt->close();
                $mysqli->commit();
                
                // Success message
                $message = "✅ Success! Imported **$imported_rows** PayPal transaction records.";
                if ($skipped_rows > 0) {
                    $message .= " Skipped **$skipped_rows** rows.";
                }
                $_SESSION['message'] = $message;
                $_SESSION['message_type'] = 'success';
                
            } catch (Exception $e) {
                if (isset($mysqli)) {
                    $mysqli->rollback();
                }
                $_SESSION['message'] = "❌ Import Error: **" . htmlspecialchars($e->getMessage()) . "**";
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
if (isset($mysqli) && $mysqli->connect_error === null) {
    try {
        // Fetch only the columns needed for the simplified table view
        $result = $mysqli->query("SELECT id, date, name, type, status, gross, fee, net, from_email, transaction_id FROM $target_table ORDER BY id DESC LIMIT 100");
        if ($result) {
            $transactions = $result->fetch_all(MYSQLI_ASSOC);
            $total_transactions = count($transactions);
        }
    } catch (Exception $e) {
        // Log database error for fetching data
        error_log("Error fetching transactions: " . $e->getMessage());
    }
}
?>

<!DOCTYPE html>
<!--html lang="en">
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

        <div class="upload-form">
            <h3>Upload PayPal Transactions Excel File</h3>
            <p>Supported formats: .xlsx, .xls, .csv</p>
            
            <form action="" method="POST" enctype="multipart/form-data">
                <input type="file" name="import_file" accept=".xlsx,.xls,.csv" required style="margin: 20px 0;">
                <br>
                <button type="submit" name="save_excel_data" class="btn">📤 Upload & Import</button>
            </form>
        </div>

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
                            <td style="text-align: right;"><?php echo number_format($transaction['gross'] ?? 0, 2); ?></td>
                            <td style="text-align: right;"><?php echo number_format($transaction['fee'] ?? 0, 2); ?></td>
                            <td style="text-align: right;"><?php echo number_format($transaction['net'] ?? 0, 2); ?></td>
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

        <div style="background: #f8f9fa; padding: 20px; border-radius: 5px; margin-top: 30px;">
            <h4>📝 Expected Excel Format (First 10 columns shown):</h4>
            <p>Your Excel file MUST contain **41 columns** in the following sequence:</p>
            <table border="1" cellpadding="8" style="width: 100%; border-collapse: collapse; font-size: 12px;">
                <tr style="background: #e9ecef;">
                    <th>0. Date</th>
                    <th>1. Time</th>
                    <th>2. Timezone</th>
                    <th>3. Name</th>
                    <th>4. Type</th>
                    <th>5. Status</th>
                    <th>6. Currency</th>
                    <th>7. Gross</th>
                    <th>8. Fee</th>
                    <th>9. Net</th>
                    <th>... 40 more columns ...</th>
                </tr>
            </table>
            <p style="margin-top: 10px; font-size: 12px; color: #666;">
                **Action:** Please ensure your database table `paypal_transactions` is created using the SQL provided in Section 1.
            </p>
        </div>
    </div>
</body>
</html-->