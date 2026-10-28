<?php
require 'db.php';
if (session_status() === PHP_SESSION_NONE) { 
    session_start(); 
}

// Helper functions
function h($s) { 
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); 
}

function formatCurrency($amount) {
    return '₹' . number_format($amount, 2);
}

function redirect_with($params = []) {
    $base = 'tds.php';
    if (!empty($params)) $base .= '?' . http_build_query($params);
    header("Location: $base");
    exit;
}

// Handle Excel Export
if (isset($_GET['export']) && $_GET['export'] === 'excel') {
    $month = $_GET['month'] ?? date('F');
    $year = $_GET['year'] ?? date('Y');
    
    // Fetch TDS data
    $tds_data = getTdsData($month, $year);
    
    // Set headers for Excel download
    header('Content-Type: application/vnd.ms-excel');
    header('Content-Disposition: attachment; filename="TDS_Report_' . $month . '_' . $year . '.xls"');
    header('Pragma: no-cache');
    header('Expires: 0');
    
    echo generateExcelContent($tds_data, $month, $year);
    exit;
}

function getTdsData($month, $year) {
    global $mysqli;
    
    $data = [
        'salary_tds' => [],
        'commission_tds' => [],
        'client_tds' => [],
        'summary' => []
    ];
    
    // Get Salary TDS
    $salary_sql = "SELECT employee_id, employee_name, employee_username, 
                          salary_month, basic_salary, gross_salary, 
                          tds_percentage, tds_amount, net_salary, status 
                   FROM employee_salary_tds 
                   WHERE salary_month = ?";
    $stmt = $mysqli->prepare($salary_sql);
    $stmt->bind_param("s", $month);
    $stmt->execute();
    $salary_result = $stmt->get_result();
    
    while ($row = $salary_result->fetch_assoc()) {
        $data['salary_tds'][] = $row;
    }
    $stmt->close();
    
    // Get Commission TDS
    $commission_sql = "SELECT member_id, member_name, month, 
                              commission_amount, tds_percentage, 
                              tds_amount, net_amount, status 
                       FROM commission_master 
                       WHERE month = ?";
    $stmt = $mysqli->prepare($commission_sql);
    $stmt->bind_param("s", $month);
    $stmt->execute();
    $commission_result = $stmt->get_result();
    
    while ($row = $commission_result->fetch_assoc()) {
        $data['commission_tds'][] = $row;
    }
    $stmt->close();
    
    // Get Client TDS from invoices
    $client_tds_sql = "SELECT i.invoice_id, i.invoice_no, i.invoice_date,
                              cm.client_id, cm.client_name, cm.company_name,
                              i.total_amount, i.tds_deduction_percent, 
                              i.tds_amount, i.final_amount_after_tds,
                              i.grand_total, i.status, i.raised_month
                       FROM invoices i
                       LEFT JOIN client_master cm ON i.client_id = cm.client_id
                       WHERE i.raised_month = ? AND i.tds_amount > 0";
    $stmt = $mysqli->prepare($client_tds_sql);
    $stmt->bind_param("s", $month);
    $stmt->execute();
    $client_tds_result = $stmt->get_result();
    
    while ($row = $client_tds_result->fetch_assoc()) {
        $data['client_tds'][] = $row;
    }
    $stmt->close();
    
    // Calculate summary
    $total_salary_tds = 0;
    $total_commission_tds = 0;
    $total_client_tds = 0;
    
    foreach ($data['salary_tds'] as $salary) {
        $total_salary_tds += $salary['tds_amount'];
    }
    
    foreach ($data['commission_tds'] as $commission) {
        $total_commission_tds += $commission['tds_amount'];
    }
    
    foreach ($data['client_tds'] as $client) {
        $total_client_tds += $client['tds_amount'];
    }
    
    $data['summary'] = [
        'total_salary_tds' => $total_salary_tds,
        'total_commission_tds' => $total_commission_tds,
        'total_client_tds' => $total_client_tds,
        'grand_total_tds' => $total_salary_tds + $total_commission_tds + $total_client_tds,
        'salary_records' => count($data['salary_tds']),
        'commission_records' => count($data['commission_tds']),
        'client_records' => count($data['client_tds'])
    ];
    
    return $data;
}

function generateExcelContent($data, $month, $year) {
    $output = "<html xmlns:o=\"urn:schemas-microsoft-com:office:office\" xmlns:x=\"urn:schemas-microsoft-com:office:excel\" xmlns=\"http://www.w3.org/TR/REC-html40\">
    <head>
        <meta charset=\"UTF-8\">
        <title>TDS Report - $month $year</title>
        <style>
            td { mso-number-format:\\@; }
            .number { mso-number-format:'#,##0.00'; }
        </style>
    </head>
    <body>
        <table border='1' cellpadding='3' style='border-collapse: collapse;'>
            <tr><th colspan='10' style='background-color: #2c3e50; color: white; font-size: 16px;'>TDS DEDUCTION REPORT - $month $year</th></tr>
            <tr><td colspan='10'><strong>Generated Date:</strong> " . date('d-m-Y H:i:s') . "</td></tr>
            <tr><td colspan='10'>&nbsp;</td></tr>";
    
    // Salary TDS Section
    if (!empty($data['salary_tds'])) {
        $output .= "
            <tr><th colspan='8' style='background-color: #34495e; color: white;'>SALARY TDS DEDUCTIONS</th></tr>
            <tr style='background-color: #f8f9fa; font-weight: bold;'>
                <th>Employee ID</th>
                <th>Employee Name</th>
                <th>Username</th>
                <th>Basic Salary</th>
                <th>Gross Salary</th>
                <th>TDS %</th>
                <th>TDS Amount</th>
                <th>Net Salary</th>
            </tr>";
        
        foreach ($data['salary_tds'] as $salary) {
            $output .= "
            <tr>
                <td>" . h($salary['employee_id']) . "</td>
                <td>" . h($salary['employee_name']) . "</td>
                <td>" . h($salary['employee_username']) . "</td>
                <td class='number'>" . number_format($salary['basic_salary'], 2) . "</td>
                <td class='number'>" . number_format($salary['gross_salary'], 2) . "</td>
                <td>" . h($salary['tds_percentage']) . "%</td>
                <td class='number'>" . number_format($salary['tds_amount'], 2) . "</td>
                <td class='number'>" . number_format($salary['net_salary'], 2) . "</td>
            </tr>";
        }
        
        $output .= "
            <tr style='background-color: #e9ecef; font-weight: bold;'>
                <td colspan='6' align='right'>Total Salary TDS:</td>
                <td class='number'>" . number_format($data['summary']['total_salary_tds'], 2) . "</td>
                <td></td>
            </tr>
            <tr><td colspan='8'>&nbsp;</td></tr>";
    }
    
    // Commission TDS Section
    if (!empty($data['commission_tds'])) {
        $output .= "
            <tr><th colspan='7' style='background-color: #34495e; color: white;'>COMMISSION TDS DEDUCTIONS</th></tr>
            <tr style='background-color: #f8f9fa; font-weight: bold;'>
                <th>Member ID</th>
                <th>Member Name</th>
                <th>Commission Amount</th>
                <th>TDS %</th>
                <th>TDS Amount</th>
                <th>Net Amount</th>
                <th>Status</th>
            </tr>";
        
        foreach ($data['commission_tds'] as $commission) {
            $output .= "
            <tr>
                <td>" . h($commission['member_id']) . "</td>
                <td>" . h($commission['member_name']) . "</td>
                <td class='number'>" . number_format($commission['commission_amount'], 2) . "</td>
                <td>" . h($commission['tds_percentage']) . "%</td>
                <td class='number'>" . number_format($commission['tds_amount'], 2) . "</td>
                <td class='number'>" . number_format($commission['net_amount'], 2) . "</td>
                <td>" . h($commission['status']) . "</td>
            </tr>";
        }
        
        $output .= "
            <tr style='background-color: #e9ecef; font-weight: bold;'>
                <td colspan='4' align='right'>Total Commission TDS:</td>
                <td class='number'>" . number_format($data['summary']['total_commission_tds'], 2) . "</td>
                <td colspan='2'></td>
            </tr>
            <tr><td colspan='7'>&nbsp;</td></tr>";
    }
    
    // Client TDS Section
    if (!empty($data['client_tds'])) {
        $output .= "
            <tr><th colspan='9' style='background-color: #34495e; color: white;'>CLIENT TDS DEDUCTIONS</th></tr>
            <tr style='background-color: #f8f9fa; font-weight: bold;'>
                <th>Invoice No</th>
                <th>Client Name</th>
                <th>Company</th>
                <th>Invoice Date</th>
                <th>Total Amount</th>
                <th>TDS %</th>
                <th>TDS Amount</th>
                <th>Final Amount</th>
                <th>Status</th>
            </tr>";
        
        foreach ($data['client_tds'] as $client) {
            $output .= "
            <tr>
                <td>" . h($client['invoice_no']) . "</td>
                <td>" . h($client['client_name']) . "</td>
                <td>" . h($client['company_name']) . "</td>
                <td>" . date('d-m-Y', strtotime($client['invoice_date'])) . "</td>
                <td class='number'>" . number_format($client['total_amount'], 2) . "</td>
                <td>" . h($client['tds_deduction_percent']) . "%</td>
                <td class='number'>" . number_format($client['tds_amount'], 2) . "</td>
                <td class='number'>" . number_format($client['final_amount_after_tds'], 2) . "</td>
                <td>" . h($client['status']) . "</td>
            </tr>";
        }
        
        $output .= "
            <tr style='background-color: #e9ecef; font-weight: bold;'>
                <td colspan='6' align='right'>Total Client TDS:</td>
                <td class='number'>" . number_format($data['summary']['total_client_tds'], 2) . "</td>
                <td colspan='2'></td>
            </tr>
            <tr><td colspan='9'>&nbsp;</td></tr>";
    }
    
    // Summary Section
    $output .= "
            <tr><th colspan='10' style='background-color: #2c3e50; color: white;'>SUMMARY</th></tr>
            <tr style='background-color: #f8f9fa; font-weight: bold;'>
                <td colspan='5'>Total Salary Records:</td>
                <td colspan='5'>" . $data['summary']['salary_records'] . "</td>
            </tr>
            <tr style='background-color: #f8f9fa; font-weight: bold;'>
                <td colspan='5'>Total Commission Records:</td>
                <td colspan='5'>" . $data['summary']['commission_records'] . "</td>
            </tr>
            <tr style='background-color: #f8f9fa; font-weight: bold;'>
                <td colspan='5'>Total Client Records:</td>
                <td colspan='5'>" . $data['summary']['client_records'] . "</td>
            </tr>
            <tr style='background-color: #e9ecef; font-weight: bold;'>
                <td colspan='5'>Total Salary TDS:</td>
                <td colspan='5' class='number'>₹" . number_format($data['summary']['total_salary_tds'], 2) . "</td>
            </tr>
            <tr style='background-color: #e9ecef; font-weight: bold;'>
                <td colspan='5'>Total Commission TDS:</td>
                <td colspan='5' class='number'>₹" . number_format($data['summary']['total_commission_tds'], 2) . "</td>
            </tr>
            <tr style='background-color: #e9ecef; font-weight: bold;'>
                <td colspan='5'>Total Client TDS:</td>
                <td colspan='5' class='number'>₹" . number_format($data['summary']['total_client_tds'], 2) . "</td>
            </tr>
            <tr style='background-color: #d4edda; font-weight: bold; font-size: 14px;'>
                <td colspan='5'><strong>GRAND TOTAL TDS:</strong></td>
                <td colspan='5' class='number'><strong>₹" . number_format($data['summary']['grand_total_tds'], 2) . "</strong></td>
            </tr>
        </table>
    </body>
    </html>";
    
    return $output;
}

// Get filter parameters
$month = $_GET['month'] ?? date('F');
$year = $_GET['year'] ?? date('Y');

// Get TDS data
$tds_data = getTdsData($month, $year);

// Get available months
$months = ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];
$current_year = date('Y');
$years = range($current_year - 2, $current_year + 1);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>TDS Monthly Report</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body {
            background-color: #f8f9fa;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }
        .main-content {
            padding: 20px;
            max-width: 1800px;
            margin: 0 auto;
        }
        .table th {
            background-color: #2c3e50;
            color: white;
            border: none;
        }
        .table td {
            vertical-align: middle;
        }
        .card {
            box-shadow: 0 0.125rem 0.25rem rgba(0, 0, 0, 0.075);
            border: 1px solid rgba(0, 0, 0, 0.125);
        }
        .amount-cell {
            text-align: right;
            font-weight: 500;
        }
        .summary-card {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border-radius: 10px;
        }
        .section-header {
            background-color: #34495e;
            color: white;
            padding: 10px 15px;
            border-radius: 5px 5px 0 0;
            margin-bottom: 0;
        }
        .salary-card { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); }
        .commission-card { background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%); }
        .client-card { background: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%); }
        .total-card { background: linear-gradient(135deg, #43e97b 0%, #38f9d7 100%); }
    </style>
</head>
<body>
    <?php if (file_exists('header.php')) require 'header.php'; ?>
    
    <div class="main-content">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h4><i class="fas fa-file-invoice-dollar me-2"></i>TDS Monthly Deduction Report</h4>
            <div>
                <a href="tds.php?export=excel&month=<?= h($month) ?>&year=<?= h($year) ?>" 
                   class="btn btn-success">
                    <i class="fas fa-file-excel me-1"></i> Export to Excel
                </a>
            </div>
        </div>

        <!-- Filter Section -->
        <div class="card mb-4">
            <div class="card-body">
                <form method="get" class="row g-3 align-items-center">
                    <div class="col-md-4">
                        <label for="month" class="form-label">Month</label>
                        <select class="form-select" id="month" name="month">
                            <?php foreach($months as $m): ?>
                                <option value="<?= h($m) ?>" <?= $month === $m ? 'selected' : '' ?>>
                                    <?= h($m) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label for="year" class="form-label">Year</label>
                        <select class="form-select" id="year" name="year">
                            <?php foreach($years as $y): ?>
                                <option value="<?= h($y) ?>" <?= $year == $y ? 'selected' : '' ?>>
                                    <?= h($y) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-4 d-flex align-items-end">
                        <button type="submit" class="btn btn-primary me-2">
                            <i class="fas fa-search me-1"></i> Generate Report
                        </button>
                        <a href="tds.php" class="btn btn-secondary">
                            <i class="fas fa-times me-1"></i> Clear
                        </a>
                    </div>
                </form>
            </div>
        </div>

        <!-- Summary Cards -->
        <div class="row mb-4">
            <div class="col-md-3">
                <div class="card summary-card salary-card">
                    <div class="card-body text-center">
                        <h5><i class="fas fa-users me-2"></i>Salary Records</h5>
                        <h2><?= $tds_data['summary']['salary_records'] ?></h2>
                        <p class="mb-0">Employees with TDS</p>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card summary-card commission-card">
                    <div class="card-body text-center">
                        <h5><i class="fas fa-handshake me-2"></i>Commission Records</h5>
                        <h2><?= $tds_data['summary']['commission_records'] ?></h2>
                        <p class="mb-0">Members with TDS</p>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card summary-card client-card">
                    <div class="card-body text-center">
                        <h5><i class="fas fa-building me-2"></i>Client Records</h5>
                        <h2><?= $tds_data['summary']['client_records'] ?></h2>
                        <p class="mb-0">Invoices with TDS</p>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card summary-card total-card">
                    <div class="card-body text-center">
                        <h5><i class="fas fa-money-bill-wave me-2"></i>Total TDS</h5>
                        <h2>₹<?= number_format($tds_data['summary']['grand_total_tds'], 2) ?></h2>
                        <p class="mb-0">Total TDS Collection</p>
                    </div>
                </div>
            </div>
        </div>

        <!-- Salary TDS Section -->
        <?php if (!empty($tds_data['salary_tds'])): ?>
        <div class="card mb-4">
            <div class="section-header">
                <h5 class="mb-0"><i class="fas fa-money-check me-2"></i>Salary TDS Deductions</h5>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Employee ID</th>
                                <th>Employee Name</th>
                                <th>Username</th>
                                <th>Basic Salary (₹)</th>
                                <th>Gross Salary (₹)</th>
                                <th>TDS %</th>
                                <th>TDS Amount (₹)</th>
                                <th>Net Salary (₹)</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach($tds_data['salary_tds'] as $salary): ?>
                            <tr>
                                <td>
                                    <span class="badge bg-primary"><?= h($salary['employee_id']) ?></span>
                                </td>
                                <td><?= h($salary['employee_name']) ?></td>
                                <td>
                                    <span class="badge bg-secondary"><?= h($salary['employee_username']) ?></span>
                                </td>
                                <td class="amount-cell">₹<?= number_format($salary['basic_salary'], 2) ?></td>
                                <td class="amount-cell">₹<?= number_format($salary['gross_salary'], 2) ?></td>
                                <td class="text-center">
                                    <span class="badge bg-warning"><?= $salary['tds_percentage'] ?>%</span>
                                </td>
                                <td class="amount-cell text-danger">
                                    <strong>₹<?= number_format($salary['tds_amount'], 2) ?></strong>
                                </td>
                                <td class="amount-cell text-success">
                                    <strong>₹<?= number_format($salary['net_salary'], 2) ?></strong>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                            <tr class="table-primary">
                                <td colspan="6" class="text-end"><strong>Total Salary TDS:</strong></td>
                                <td class="amount-cell text-danger">
                                    <strong>₹<?= number_format($tds_data['summary']['total_salary_tds'], 2) ?></strong>
                                </td>
                                <td></td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        <?php else: ?>
        <div class="card mb-4">
            <div class="card-body text-center py-5">
                <i class="fas fa-money-check fa-3x text-muted mb-3"></i>
                <h5>No Salary TDS Records Found</h5>
                <p class="text-muted">No salary TDS deductions for <?= h($month) ?> <?= h($year) ?></p>
            </div>
        </div>
        <?php endif; ?>

        <!-- Commission TDS Section -->
        <?php if (!empty($tds_data['commission_tds'])): ?>
        <div class="card mb-4">
            <div class="section-header">
                <h5 class="mb-0"><i class="fas fa-handshake me-2"></i>Commission TDS Deductions</h5>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Member ID</th>
                                <th>Member Name</th>
                                <th>Commission Amount (₹)</th>
                                <th>TDS %</th>
                                <th>TDS Amount (₹)</th>
                                <th>Net Amount (₹)</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach($tds_data['commission_tds'] as $commission): ?>
                            <tr>
                                <td>
                                    <span class="badge bg-primary"><?= h($commission['member_id']) ?></span>
                                </td>
                                <td><?= h($commission['member_name']) ?></td>
                                <td class="amount-cell">₹<?= number_format($commission['commission_amount'], 2) ?></td>
                                <td class="text-center">
                                    <span class="badge bg-warning"><?= $commission['tds_percentage'] ?>%</span>
                                </td>
                                <td class="amount-cell text-danger">
                                    <strong>₹<?= number_format($commission['tds_amount'], 2) ?></strong>
                                </td>
                                <td class="amount-cell text-success">
                                    <strong>₹<?= number_format($commission['net_amount'], 2) ?></strong>
                                </td>
                                <td>
                                    <span class="badge <?= $commission['status'] === 'Paid' ? 'bg-success' : 'bg-warning' ?>">
                                        <?= h($commission['status']) ?>
                                    </span>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                            <tr class="table-primary">
                                <td colspan="4" class="text-end"><strong>Total Commission TDS:</strong></td>
                                <td class="amount-cell text-danger">
                                    <strong>₹<?= number_format($tds_data['summary']['total_commission_tds'], 2) ?></strong>
                                </td>
                                <td colspan="2"></td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        <?php else: ?>
        <div class="card mb-4">
            <div class="card-body text-center py-5">
                <i class="fas fa-handshake fa-3x text-muted mb-3"></i>
                <h5>No Commission TDS Records Found</h5>
                <p class="text-muted">No commission TDS deductions for <?= h($month) ?> <?= h($year) ?></p>
            </div>
        </div>
        <?php endif; ?>

        <!-- Client TDS Section -->
        <?php if (!empty($tds_data['client_tds'])): ?>
        <div class="card mb-4">
            <div class="section-header">
                <h5 class="mb-0"><i class="fas fa-building me-2"></i>Client TDS Deductions</h5>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Invoice No</th>
                                <th>Client Name</th>
                                <th>Company</th>
                                <th>Invoice Date</th>
                                <th>Total Amount (₹)</th>
                                <th>TDS %</th>
                                <th>TDS Amount (₹)</th>
                                <th>Final Amount (₹)</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach($tds_data['client_tds'] as $client): ?>
                            <tr>
                                <td>
                                    <span class="badge bg-info"><?= h($client['invoice_no']) ?></span>
                                </td>
                                <td><?= h($client['client_name']) ?></td>
                                <td><?= h($client['company_name']) ?></td>
                                <td>
                                    <small class="text-muted"><?= date('d-m-Y', strtotime($client['invoice_date'])) ?></small>
                                </td>
                                <td class="amount-cell">₹<?= number_format($client['total_amount'], 2) ?></td>
                                <td class="text-center">
                                    <span class="badge bg-warning"><?= $client['tds_deduction_percent'] ?>%</span>
                                </td>
                                <td class="amount-cell text-danger">
                                    <strong>₹<?= number_format($client['tds_amount'], 2) ?></strong>
                                </td>
                                <td class="amount-cell text-success">
                                    <strong>₹<?= number_format($client['final_amount_after_tds'], 2) ?></strong>
                                </td>
                                <td>
                                    <span class="badge <?= $client['status'] === 'Paid' ? 'bg-success' : ($client['status'] === 'Pending' ? 'bg-warning' : 'bg-danger') ?>">
                                        <?= h($client['status']) ?>
                                    </span>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                            <tr class="table-primary">
                                <td colspan="6" class="text-end"><strong>Total Client TDS:</strong></td>
                                <td class="amount-cell text-danger">
                                    <strong>₹<?= number_format($tds_data['summary']['total_client_tds'], 2) ?></strong>
                                </td>
                                <td colspan="2"></td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        <?php else: ?>
        <div class="card mb-4">
            <div class="card-body text-center py-5">
                <i class="fas fa-building fa-3x text-muted mb-3"></i>
                <h5>No Client TDS Records Found</h5>
                <p class="text-muted">No client TDS deductions for <?= h($month) ?> <?= h($year) ?></p>
            </div>
        </div>
        <?php endif; ?>

        <!-- Grand Total Summary -->
        <div class="card">
            <div class="card-body">
                <div class="row text-center">
                    <div class="col-md-3">
                        <h5 class="text-primary">Total Salary TDS</h5>
                        <h3 class="text-danger">₹<?= number_format($tds_data['summary']['total_salary_tds'], 2) ?></h3>
                    </div>
                    <div class="col-md-3">
                        <h5 class="text-primary">Total Commission TDS</h5>
                        <h3 class="text-danger">₹<?= number_format($tds_data['summary']['total_commission_tds'], 2) ?></h3>
                    </div>
                    <div class="col-md-3">
                        <h5 class="text-primary">Total Client TDS</h5>
                        <h3 class="text-danger">₹<?= number_format($tds_data['summary']['total_client_tds'], 2) ?></h3>
                    </div>
                    <div class="col-md-3">
                        <h5 class="text-success">Grand Total TDS</h5>
                        <h3 class="text-success">₹<?= number_format($tds_data['summary']['grand_total_tds'], 2) ?></h3>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>