<?php
session_start();
require 'db.php'; // Includes the $mysqli connection object

// Check authentication
if (!isset($_SESSION['username'])) {
    $_SESSION['message'] = "Session expired. Please log in again.";
    header('Location: index.php');
    exit(0);
}

// --- INCLUDE EXCEL LIBRARIES ---
// Ensure this path is correct for your SimpleXLSX installation
require_once __DIR__ . '/simplexlsx-master/src/SimpleXLSX.php';
use Shuchkin\SimpleXLSX;

// Define the TARGET TABLE NAME
$target_table = 'input_output';

if (isset($_POST['save_excel_data'])) {
    
    // Check for file upload errors (omitted for brevity, assume good)

    $fileName = $_FILES['import_file']['name'];
    $inputFileNamePath = $_FILES['import_file']['tmp_name'];
    $allowed_ext = ['xlsx', 'xls', 'csv']; 
    $file_ext = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));

    // 1. Validate file extension (omitted for brevity, assume good)

    try {
        // 2. Load the spreadsheet using SimpleXLSX (omitted for brevity, assume good)
        $xlsx = SimpleXLSX::parse($inputFileNamePath);
        if (!$xlsx) throw new Exception('Error reading or parsing the Excel file. Check file integrity.');
        $data = $xlsx->rows(); 

        if (empty($data)) {
            $_SESSION['message'] = "❌ The Excel file is empty or could not be read.";
            header('Location: job_management.php');
            exit(0);
        }

        $imported_rows = 0;
        
        // Start transaction for atomic operation
        $mysqli->begin_transaction();
        $is_error = false;
        
        // Prepare the INSERT statement 
        $sql = "INSERT INTO $target_table (
            job_date, le_date, ce_date, client, journal, articles, pages, eqn, 
            images, tables_count, ce_done_by, le_done_by, pag_done_by, qc_done_by, 
            due_date, rtat, atat, dispatch_date, upload_by, file_path, 
            client_status, ce_status, le_status, pgn_status, qc_status
        ) VALUES (
            ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 
            ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 
            ?, ?, ?, ?, ?
        )";
        
        $stmt = $mysqli->prepare($sql);
        if (!$stmt) throw new Exception("Database prepare error: " . $mysqli->error);
        
        // *******************************************************************
        // FIX: The binding string must now be all 's' (string) for 25 columns
        // *******************************************************************
        $types = "sssssssssssssssssssssssss"; // 25 's' characters
        
        $stmt->bind_param(
            $types, 
            $job_date, $le_date, $ce_date, $client, $journal, $articles, $pages, $eqn, 
            $images, $tables_count, $ce_done_by, $le_done_by, $pag_done_by, $qc_done_by, 
            $due_date, $rtat, $atat, $dispatch_date, $upload_by, $file_path, 
            $client_status, $ce_status, $le_status, $pgn_status, $qc_status
        );
        
        // 3. Loop through rows and insert into database
        $is_header = true;
        foreach ($data as $row) {
            if ($is_header) {
                $is_header = false; 
                continue;
            }

            if (empty(array_filter($row))) continue; 
            
            // *******************************************************************
            // FIX: Removed date/int conversions. Data is inserted as raw string.
            // *******************************************************************
           // $job_date      = $row[0] ?? null;
            $job_date      = $row[0] ?? null;
            $le_date       = $row[1] ?? null;
            $ce_date       = $row[2] ?? null;
            $client        = $row[3] ?? null;
            $journal       = $row[4] ?? null;
            $articles      = $row[5] ?? null;
            $pages         = $row[6] ?? null;
            $eqn           = $row[7] ?? null;
            $images        = $row[8] ?? null;
            $tables_count  = $row[9] ?? null;
            $ce_done_by    = $row[10] ?? null;
            $le_done_by    = $row[11] ?? null;
            $pag_done_by   = $row[12] ?? null;
            $qc_done_by    = $row[13] ?? null;
            $due_date      = $row[14] ?? null;
            $rtat          = $row[15] ?? null;
            $atat          = $row[16] ?? null;
            $dispatch_date = $row[17] ?? null;
            $upload_by     = $row[18] ?? null;
            $file_path     = $row[19] ?? null;
            $client_status = $row[20] ?? 'Completed';
            $ce_status     = $row[21] ?? 'Completed';
            $le_status     = $row[22] ?? 'Completed';
            $pgn_status    = $row[23] ?? 'Completed';
            $qc_status     = $row[24] ?? 'Completed'; 

            if (!$stmt->execute()) {
                $is_error = true;
                // Temporarily uncomment the line below for debugging ONLY
                // $_SESSION['message'] = "❌ Insert failed on row " . ($imported_rows + 2) . ": " . $stmt->error;
                error_log("Failed to insert into $target_table: " . $stmt->error . " | Row data: " . json_encode($row)); 
                break; 
            }
            $imported_rows++;
        }
        
        $stmt->close();

        // 4. Finalize transaction and redirect (omitted for brevity, assume good)
        if ($is_error) {
            $mysqli->rollback();
            $_SESSION['message'] = $_SESSION['message'] ?? "❌ Import failed! Rolled back changes. Check PHP error logs for details.";
        } else {
            $mysqli->commit();
            $_SESSION['message'] = "✅ **Success!** Imported $imported_rows new job records into $target_table.";
        }

    } catch (\Exception $e) {
        $_SESSION['message'] = "❌ An unexpected error occurred: " . $e->getMessage();
    }

    header('Location: input_output_view.php');
    exit(0);
} else {
    header('Location: input_output_view.php');
    exit(0);
}
?>