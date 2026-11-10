<?php
header("Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet");
header("Content-Disposition: attachment;filename=\"extra_charges_template.xlsx\"");
header("Cache-Control: max-age=0");

// Create sample Excel file
$data = [
    ["Sales InCharge", "Send Date", "Client Name", "Article/Journal", "Count", "Particular", "Process", "Charges", "Client Confirmed", "Team Status", "Team Confirmed", "Remarks"],
    ["John Doe", "2024-01-15", "ABC Corporation", "Journal Article 1", 2, "Additional Editing", "Editing", 1500.00, "2024-01-20", "Pending", "", "Urgent"],
    ["Jane Smith", "2024-01-16", "XYZ Ltd", "Research Paper", 1, "Formatting", "Formatting", 750.00, "2024-01-18", "Completed", "2024-01-19", "Standard"],
    ["Mike Johnson", "2024-01-17", "Current Human Resource", "Quarterly Report", 5, "CE+TS", "Typesetting", 2000.00, "2024-01-25", "In Progress", "", "Quarterly publication"]
];

// Using SimpleXLSX to create file
$libPath = dirname(__DIR__) . "/simplexlsx-master/src/SimpleXLSXGen.php";
if (!file_exists($libPath)) $libPath = "simplexlsx-master/src/SimpleXLSXGen.php";

if (file_exists($libPath)) {
    require_once $libPath;
    $xlsx = Shuchkin\SimpleXLSXGen::fromArray($data);
    $xlsx->downloadAs("extra_charges_template.xlsx");
} else {
    echo "Library not found. Please install SimpleXLSXGen.";
}
?>