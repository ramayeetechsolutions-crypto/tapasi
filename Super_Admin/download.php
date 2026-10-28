<?php
// download.php - Located in Super_Admin/
require_once 'db.php';

/**
 * 1. SET THE BASE DIRECTORY
 * __DIR__ points to 'C:\Apache24\htdocs\tapasi\Super_Admin'
 * We append '/invoices/' to reach the folder where PDFs are stored.
 */
$base_dir = __DIR__ . DIRECTORY_SEPARATOR . 'invoices' . DIRECTORY_SEPARATOR;

// 2. GET THE REQUESTED FILE
// Expecting: Client_Alpha1_2026-02/INV_87_2026_02.pdf
$requested_file = $_GET['file'] ?? '';

if (empty($requested_file)) {
    header("HTTP/1.1 400 Bad Request");
    die('Error: No file specified.');
}

/**
 * 3. SANITIZE AND CONSTRUCT PATH
 * We remove ".." to prevent users from accessing files outside the invoices folder.
 * We trim leading slashes so the path joins correctly.
 */
$requested_file = str_replace(['..', '\\\\'], '', $requested_file);
$requested_file = ltrim($requested_file, '/\\');

$file_path = $base_dir . $requested_file;

/**
 * 4. VALIDATION
 * Check if the file physically exists on the disk.
 */
if (!file_exists($file_path) || !is_file($file_path)) {
    header("HTTP/1.1 404 Not Found");
    // For security, you might not want to show the full $file_path to the user
    die('Error: The requested invoice does not exist on the server.');
}

// 5. PREPARE HEADERS
$filename = basename($file_path);

// Clear any previous output (whitespace from db.php, etc.) to prevent PDF corruption
if (ob_get_level()) {
    ob_end_clean();
}

header('Content-Description: File Transfer');
header('Content-Type: application/pdf');
// 'attachment' forces download. Change to 'inline' to open in browser.
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Expires: 0');
header('Cache-Control: must-revalidate');
header('Pragma: public');
header('Content-Length: ' . filesize($file_path));

/**
 * 6. STREAM FILE
 * readfile() is memory efficient for large files.
 */
readfile($file_path);
exit;
?>