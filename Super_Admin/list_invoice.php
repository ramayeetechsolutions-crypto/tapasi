<?php
// ===============================================
// 1. DATABASE CONNECTION SETUP
// ===============================================
// Include the database connection file.
// This file must define and initialize the $mysqli variable.
require_once 'db.php'; 
require_once 'header.php'; 

// ===============================================
// 2. FILE SYSTEM AND DATABASE LISTING LOGIC
// ===============================================

// Define the directory relative to the current script (Super_Admin/list_invoices.php)
$upload_dir = '../SubAdmin/invoices'; 
// Use an associative array (set) to store unique filenames
$unique_invoices = []; 

## A. Fetch from File System (Original Logic)
if (is_dir($upload_dir)) {
    // Find all files starting with 'INV_' and ending with '.pdf'
    $files = glob($upload_dir . '/INV_*.pdf'); 
    
    foreach ($files as $file_path) {
        $filename = basename($file_path);
        // Add to the set
        $unique_invoices[$filename] = true; 
    }
}

## B. Fetch from job_uploads table (Using MySQLi)
$query_uploads = "SELECT `file_path` FROM `job_uploads` WHERE `file_path` IS NOT NULL AND `file_path` != ''";
$result_uploads = $mysqli->query($query_uploads);

if ($result_uploads && $result_uploads->num_rows > 0) {
    while ($row = $result_uploads->fetch_assoc()) {
        // Extract the filename from the path
        $filename = basename($row['file_path']);
        
        // Check if the file actually exists on the disk before listing it
        if (is_file($upload_dir . '/' . $filename)) {
            $unique_invoices[$filename] = true;
        }
    }
    $result_uploads->free();
}

## C. Fetch from job_uploads_qc table (Using MySQLi)
$query_qc = "SELECT `file_path` FROM `job_uploads_qc` WHERE `file_path` IS NOT NULL AND `file_path` != ''";
$result_qc = $mysqli->query($query_qc);

if ($result_qc && $result_qc->num_rows > 0) {
    while ($row = $result_qc->fetch_assoc()) {
        // Extract the filename from the path
        $filename = basename($row['file_path']);
        
        // Check if the file actually exists on the disk before listing it
        if (is_file($upload_dir . '/' . $filename)) {
            $unique_invoices[$filename] = true;
        }
    }
    $result_qc->free();
}

// Close the database connection (optional, but good practice)
$mysqli->close();

// Convert the keys of the unique set back into a simple array for easy display
$invoices = array_keys($unique_invoices);

// ===============================================
// 3. HTML DISPLAY
// ===============================================
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Super Admin: Combined Available Files</title>
</head>
<body>
    <br><br><br>
    <h1>Combined Available Files (Invoices & Job Uploads)</h1>

    <?php if (empty($invoices)): ?>
        <p>No files found either on the disk or referenced in the database.</p>
    <?php else: ?>
        <h2>Available Files (Total: <?php echo count($invoices); ?>)</h2>
        <ul>
            <?php foreach ($invoices as $filename): ?>
                <li>
                    <a href="download.php?file=<?php echo urlencode($filename); ?>">
                        📥 Download <?php echo htmlspecialchars($filename); ?>
                    </a>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
</body>
</html>