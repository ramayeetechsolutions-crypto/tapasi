<?php
require 'db.php';
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Start output buffering
ob_start();

// Set headers for Excel file
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename=UPS_Export_' . date('Y-m-d') . '.csv');

// Create output stream
$output = fopen('php://output', 'w');

// Add BOM for UTF-8
fputs($output, "\xEF\xBB\xBF");

// Write headers
$headers = ['UPS No.', 'Team', 'Date_Bat 1', 'Date_Bat 2', 'Ageing (Months)', 'Remarks', 'Battery1_Serial', 'Battery2_Serial', 'Status', 'Created At'];
fputcsv($output, $headers);

// Fetch data from database
$sql = "SELECT ups_no, team, date_battery1, date_battery2, ageing, remarks, battery1_serial, battery2_serial, status, created_at 
        FROM tbl_ups 
        ORDER BY ageing DESC, ups_no ASC";
$result = $mysqli->query($sql);

// Write data rows
if ($result && $result->num_rows > 0) {
    while ($row = $result->fetch_assoc()) {
        // Format dates
        $date1 = !empty($row['date_battery1']) && $row['date_battery1'] !== '0000-00-00' ? date('d-m-Y', strtotime($row['date_battery1'])) : '';
        $date2 = !empty($row['date_battery2']) && $row['date_battery2'] !== '0000-00-00' ? date('d-m-Y', strtotime($row['date_battery2'])) : '';
        $created = !empty($row['created_at']) ? date('d-m-Y H:i', strtotime($row['created_at'])) : '';
        
        $csvRow = [
            $row['ups_no'] ?? '',
            $row['team'] ?? '',
            $date1,
            $date2,
            $row['ageing'] ?? 0,
            $row['remarks'] ?? '',
            $row['battery1_serial'] ?? '',
            $row['battery2_serial'] ?? '',
            $row['status'] ?? 'active',
            $created
        ];
        
        fputcsv($output, $csvRow);
    }
}

// Close output stream
fclose($output);

// Flush output buffer
ob_end_flush();
exit;