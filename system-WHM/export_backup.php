<?php
require 'db.php';

// Fetch all backups
$sql = "SELECT * FROM tbl_backups ORDER BY backup_date DESC, created_at DESC";
$result = $mysqli->query($sql);

if (!$result) {
    die("Query failed: " . $mysqli->error);
}

// Set headers for CSV download
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="backup_records_' . date('Y-m-d') . '.csv"');

// Create output stream
$output = fopen('php://output', 'w');

// Add UTF-8 BOM for Excel compatibility
fwrite($output, "\xEF\xBB\xBF");

// Add CSV headers
fputcsv($output, [
    'Team', 
    'Folder Name', 
    'Backup Date', 
    'Backup Name', 
    'Year',
    'Path',
    'Status',
    'Start Date',
    'End Date',
    'Created At'
]);

// Add data rows
while ($row = $result->fetch_assoc()) {
    fputcsv($output, [
        $row['team'],
        $row['folder_name'],
        $row['backup_date'],
        $row['backup_name'],
        $row['year'],
        $row['path'],
        $row['status'],
        $row['start_date'],
        $row['end_date'],
        $row['created_at']
    ]);
}

fclose($output);
exit;