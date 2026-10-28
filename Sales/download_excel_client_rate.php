<?php
// download_excel_client_rate.php
header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="client_rate_list_template.xlsx"');

// SimpleXLSX library to create Excel file
$libPath = dirname(__DIR__) . '/simplexlsx-master/src/SimpleXLSX.php';
if (!file_exists($libPath)) $libPath = dirname(__DIR__) . '/simplexlsx-master/SimpleXLSX.php';
if (!file_exists($libPath)) $libPath = 'simplexlsx-master/src/SimpleXLSX.php';

if (file_exists($libPath)) {
    require_once $libPath;
    
    // Create data array
    $data = [
        ['client_name', 'payment', 'frequency', 'y0j', 'initial', 'invoice_name', 'all_services', 'ce_ts', 'ce_ts_xml', 'ce', 'ts', 'xml', 'html', 'xml_html'],
        ['Example Client 1', 'Monthly', 'Weekly', '2023', 'Initial Value', 'Invoice Name 1', 'Yes', 'No', 'Yes', 'No', 'Yes', 'No', 'Yes', 'No'],
        ['Example Client 2', 'Quarterly', 'Monthly', '2022', 'Initial 2', 'Invoice Name 2', 'No', 'Yes', 'No', 'Yes', 'No', 'Yes', 'No', 'Yes'],
        ['', '', '', '', '', '', '', '', '', '', '', '', '', ''],
        ['INSTRUCTIONS:', '', '', '', '', '', '', '', '', '', '', '', '', ''],
        ['1. Fill data starting from row 2', '', '', '', '', '', '', '', '', '', '', '', '', ''],
        ['2. client_name must exist in database', '', '', '', '', '', '', '', '', '', '', '', '', ''],
        ['3. Service columns accept "Yes" or "No"', '', '', '', '', '', '', '', '', '', '', '', '', ''],
        ['4. Remove example rows before uploading', '', '', '', '', '', '', '', '', '', '', '', '', ''],
    ];
    
    // Create SimpleXLSX object
    $xlsx = new Shuchkin\SimpleXLSXGen();
    $xlsx->addSheet($data, 'Template');
    
    // Output the file
    echo $xlsx->saveAsPHP();
} else {
    // Fallback to CSV if SimpleXLSX not available
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="client_rate_list_template.csv"');
    
    $data = [
        ['client_name', 'payment', 'frequency', 'y0j', 'initial', 'invoice_name', 'all_services', 'ce_ts', 'ce_ts_xml', 'ce', 'ts', 'xml', 'html', 'xml_html'],
        ['Example Client 1', 'Monthly', 'Weekly', '2023', 'Initial Value', 'Invoice Name 1', 'Yes', 'No', 'Yes', 'No', 'Yes', 'No', 'Yes', 'No'],
        ['Example Client 2', 'Quarterly', 'Monthly', '2022', 'Initial 2', 'Invoice Name 2', 'No', 'Yes', 'No', 'Yes', 'No', 'Yes', 'No', 'Yes'],
    ];
    
    $output = fopen('php://output', 'w');
    foreach ($data as $row) {
        fputcsv($output, $row);
    }
    fclose($output);
}
exit;