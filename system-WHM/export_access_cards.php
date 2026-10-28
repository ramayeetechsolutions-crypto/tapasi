<?php
require 'db.php';

// Fetch all access cards
$sql = "SELECT * FROM tbl_access_cards ORDER BY created_at DESC";
$result = $mysqli->query($sql);

if (!$result) {
    die("Query failed: " . $mysqli->error);
}

// Set headers for Excel download
header('Content-Type: application/vnd.ms-excel');
header('Content-Disposition: attachment; filename="access_cards_' . date('Y-m-d') . '.xls"');

// Start HTML table with Excel format
echo '<table border="1">';
echo '<tr>';
echo '<th>Access No</th>';
echo '<th>Name</th>';
echo '<th>Team</th>';
echo '<th>Emp ID</th>';
echo '<th>Card Fee</th>';
echo '<th>Issue Type</th>';
echo '<th>Issue Date</th>';
echo '<th>Created At</th>';
echo '</tr>';

// Add data rows
while ($row = $result->fetch_assoc()) {
    echo '<tr>';
    echo '<td>' . htmlspecialchars($row['access_no']) . '</td>';
    echo '<td>' . htmlspecialchars($row['name']) . '</td>';
    echo '<td>' . htmlspecialchars($row['team']) . '</td>';
    echo '<td>' . htmlspecialchars($row['emp_id']) . '</td>';
    echo '<td>' . htmlspecialchars($row['card_fee']) . '</td>';
    echo '<td>' . htmlspecialchars($row['issue_type']) . '</td>';
    echo '<td>' . htmlspecialchars($row['issue_date']) . '</td>';
    echo '<td>' . htmlspecialchars($row['created_at']) . '</td>';
    echo '</tr>';
}

echo '</table>';
exit;
?>