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
require_once __DIR__ . '/simplexlsx-master/src/SimpleXLSX.php';
use Shuchkin\SimpleXLSX;

// Define the TARGET TABLES
$target_tables = ['jobs', 'ce', 'le', 'pag', 'qc'];

if (isset($_POST['save_excel_data'])) {
 
  // --- File Validation (omitted for brevity) ---
  $fileName = $_FILES['import_file']['name'];
  $inputFileNamePath = $_FILES['import_file']['tmp_name'];
  $allowed_ext = ['xlsx', 'xls', 'csv'];
  $file_ext = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));

  if (!in_array($file_ext, $allowed_ext)) {
    $_SESSION['message'] = "Invalid File: Only .xlsx, .xls, and .csv are allowed.";
    header('Location: job_management.php');
    exit(0);
  }
  // --- End File Validation ---

  try {
    $xlsx = SimpleXLSX::parse($inputFileNamePath);
    if (!$xlsx) throw new Exception('Error reading or parsing the Excel file.');
    $data = $xlsx->rows();

    if (empty($data)) {
      $_SESSION['message'] = "❌ The Excel file is empty or could not be read.";
      header('Location: job_management.php');
      exit(0);
    }

    $imported_rows = 0;
    $mysqli->begin_transaction();
    $is_error = false;
    **$db_error_message = ""; // New variable to store the specific DB error**
   
    // --- 1. PREPARE STATEMENTS (Assuming SQL structure is correct from previous fix) ---

    // A. jobs (6 columns)
    $sql_jobs = "INSERT INTO jobs (
      job_id, job_date, client_id, journal_issue, article, due_date
    ) VALUES (
      ?, ?, ?, ?, ?, ?
    )";
    $stmt_jobs = $mysqli->prepare($sql_jobs);
    if (!$stmt_jobs) throw new Exception("Prepare Error (jobs): " . $mysqli->error);
    $types_jobs = "ssssss";

    // B. ce (16 columns)
    $sql_ce = "INSERT INTO ce (
      job_id, date, client, issue, articles, eq, images, tables_count, word_count, tat, status, ce_date,
      eq_check, images_check, tables_count_check, ref_check
    ) VALUES (
      ?, ?, ?, ?, ?, ?, ?, ?, ?, ?,
      ?, ?, ?, ?, ?, ?
    )";
    $stmt_ce = $mysqli->prepare($sql_ce);
    if (!$stmt_ce) throw new Exception("Prepare Error (ce): " . $mysqli->error);
    $types_ce = "ssssssssssssssss";

    // C. le (18 columns)
    $sql_le = "INSERT INTO le (
      job_id, date, client, issue, articles, eq, images, tables_count,
      word_count, ref, tat, status, le_date, due_date,
      eq_check, tables_count_check, images_check, ref_check
    ) VALUES (
      ?, ?, ?, ?, ?, ?, ?, ?, ?, ?,
      ?, ?, ?, ?, ?, ?, ?, ?
    )";
    $stmt_le = $mysqli->prepare($sql_le);
    if (!$stmt_le) throw new Exception("Prepare Error (le): " . $mysqli->error);
    $types_le = "ssssssssssssssssss";

    // D. pag (19 columns)
    $sql_pag = "INSERT INTO pag (
      job_id, date, client, issue, articles, process, pages, images,
      tables_count, done_by, tat, r_tat, a_tat, status, due_date, pag_date,
      pages_check, tables_count_check, images_check
    ) VALUES (
      ?, ?, ?, ?, ?, ?, ?, ?, ?, ?,
      ?, ?, ?, ?, ?, ?, ?, ?, ?
    )";
    $stmt_pag = $mysqli->prepare($sql_pag);
    if (!$stmt_pag) throw new Exception("Prepare Error (pag): " . $mysqli->error);
    $types_pag = "sssssssssssssssssss";

    // E. qc (23 columns)
    $sql_qc = "INSERT INTO qc (
      job_id, date, client, journal_issue, article, pages, images, tables_count,
      process, due_date, ce_date, ce_done_by, le_date, le_done_by, pag_date,
      pag_done_by, rtat, atat, dispatch_date, upload_by, path, status, done_by
    ) VALUES (
      ?, ?, ?, ?, ?, ?, ?, ?, ?, ?,
      ?, ?, ?, ?, ?, ?, ?, ?, ?, ?,
      ?, ?, ?
    )";
    $stmt_qc = $mysqli->prepare($sql_qc);
    if (!$stmt_qc) throw new Exception("Prepare Error (qc): " . $mysqli->error);
    $types_qc = "sssssssssssssssssssssss";

    // --- 2. LOOP AND INSERT ---
   
    $is_header = true;
    foreach ($data as $row) {
      if ($is_header) {
        $is_header = false;
        continue;
      }
      if (empty(array_filter($row))) continue;

      // **MAPPING:** Use the 25 columns from the Excel row ($row[0] to $row[24])
      $job_id   = $row[0] ?? null; 
      $job_date  = $row[1] ?? null;
      $le_date  = $row[2] ?? null;
      $ce_date  = $row[3] ?? null;
      $client   = $row[4] ?? null;
      $journal  = $row[5] ?? null;
      $articles  = $row[6] ?? null;
      $pages   = $row[7] ?? null;
      $eqn    = $row[8] ?? null;
      $images   = $row[9] ?? null;
      $tables_count = $row[10] ?? null;
      $ce_done_by = $row[11] ?? null;
      $le_done_by = $row[12] ?? null;
      $pag_done_by = $row[13] ?? null;
      $qc_done_by = $row[14] ?? null; 
      $due_date  = $row[15] ?? null;
      $rtat    = $row[16] ?? null;
      $atat    = $row[17] ?? null;
      $dispatch_date = $row[18] ?? null;
      $upload_by = $row[19] ?? null;
      $file_path = $row[20] ?? null;
      $client_status = $row[21] ?? 'Completed';
      $ce_status = $row[22] ?? 'Completed';
      $le_status = $row[23] ?? 'Completed';
      $pgn_status = $row[24] ?? 'Completed';
      $qc_status = $row[25] ?? 'Completed';

      // --- INSERT 1: jobs table ---
      $stmt_jobs->bind_param($types_jobs,
        $job_id, $job_date, $client, $journal, $articles, $due_date
      );
      if (!$stmt_jobs->execute()) { 
                $is_error = true; 
                **$db_error_message = "jobs table error: " . $stmt_jobs->error;**
                break; 
            }

      // --- INSERT 2: ce table ---
      $stmt_ce->bind_param($types_ce,
        $job_id, $job_date, $client, $journal, $articles, $eqn, $images, 
                $tables_count, $articles, $rtat, $ce_status, $ce_date, 
                '1', '1', '1', '1' 
      );
      if (!$stmt_ce->execute()) { 
                $is_error = true; 
                **$db_error_message = "ce table error: " . $stmt_ce->error;**
                break; 
            }

      // --- INSERT 3: le table ---
      $stmt_le->bind_param($types_le,
        $job_id, $job_date, $client, $journal, $articles, $eqn, $images, 
                $tables_count, $articles, $articles, $rtat, $le_status, $le_date, 
                $due_date, '1', '1', '1', '1'
      );
      if (!$stmt_le->execute()) { 
                $is_error = true; 
                **$db_error_message = "le table error: " . $stmt_le->error;**
                break; 
            }

      // --- INSERT 4: pag table ---
      $stmt_pag->bind_param($types_pag,
        $job_id, $job_date, $client, $journal, $articles, $pgn_status, $pages, $images, 
                $tables_count, $pag_done_by, $rtat, $rtat, $atat, $pgn_status, 
                $due_date, $dispatch_date, '1', '1', '1' 
      );
      if (!$stmt_pag->execute()) { 
                $is_error = true; 
                **$db_error_message = "pag table error: " . $stmt_pag->error;**
                break; 
            }
     
      // --- INSERT 5: qc table ---
      $stmt_qc->bind_param($types_qc,
        $job_id, $job_date, $client, $journal, $articles, $pages, $images, $tables_count, 
                $qc_status, $due_date, $ce_date, $ce_done_by, $le_date, $le_done_by, 
                $dispatch_date, $pag_done_by, $rtat, $atat, $dispatch_date, $upload_by, 
                $file_path, $qc_status, $qc_done_by
      );
      if (!$stmt_qc->execute()) { 
                $is_error = true; 
                **$db_error_message = "qc table error: " . $stmt_qc->error;**
                break; 
            }

      $imported_rows++;
    }
   
    $stmt_jobs->close();
    $stmt_ce->close();
    $stmt_le->close();
    $stmt_pag->close();
    $stmt_qc->close();

    // --- 3. Finalize transaction ---
    if ($is_error) {
      $mysqli->rollback();
      $_SESSION['message'] = "❌ Multi-table Import failed! Rolled back changes. **DB Error: " . $db_error_message . "**";
    } else {
      $mysqli->commit();
      $_SESSION['message'] = "✅ **Success!** Imported $imported_rows jobs across 5 tables.";
    }

  } catch (\Exception $e) {
    // This captures errors in prepare() or file reading
    error_log("General Import Exception: " . $e->getMessage() . " in file: " . $e->getFile() . " on line: " . $e->getLine());
    $_SESSION['message'] = "❌ An unexpected error occurred: **" . $e->getMessage() . "**";
  }

  // Redirect to your main view page
  header('Location: job_management.php');
  exit(0);
} else {
  // If not a POST request, redirect to your main view page
  header('Location: job_management.php');
  exit(0);
}
?>