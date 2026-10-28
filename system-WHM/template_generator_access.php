<?php
// Generate a simple CSV template file
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="access_card_template.csv"');

$output = fopen('php://output', 'w');

// Headers with descriptions
fputcsv($output, [
    'Access No (required)',
    'Name',
    'Team',
    'Emp ID',
    'Card Fee',
    'Issue Type (New/Missing/Fine)',
    'Issue Date (YYYY-MM-DD)'
]);

// Example rows
fputcsv($output, ['TPS001', 'R. Shanthi', 'CE', 'TPS001', '100', 'New', date('Y-m-d')]);
fputcsv($output, ['TPS002', 'T.G. Bragadeeshwaran', 'UNIQUE', 'TPS002', '100', 'Missing', date('Y-m-d')]);
fputcsv($output, ['TPS003', 'D. Mohan', 'Image', 'TPS003', '10', 'Fine', date('Y-m-d')]);

fclose($output);
exit;
?>