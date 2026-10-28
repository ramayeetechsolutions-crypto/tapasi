<?php
require 'db.php';
if (session_status() === PHP_SESSION_NONE) { 
    session_start(); 
}

// Helper functions
function h($s) { 
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); 
}

function formatDate($d) {
    if (empty($d) || $d === '0000-00-00' || $d === '0000-00-00 00:00:00') return '—';
    return date('d-m-Y', strtotime($d));
}

function formatCurrency($amount) {
    return '₹' . number_format($amount, 2);
}

function numberToWords($number) {
    $ones = array("", "One", "Two", "Three", "Four", "Five", "Six", "Seven", "Eight", "Nine", "Ten", "Eleven", "Twelve", "Thirteen", "Fourteen", "Fifteen", "Sixteen", "Seventeen", "Eighteen", "Nineteen");
    $tens = array("", "", "Twenty", "Thirty", "Forty", "Fifty", "Sixty", "Seventy", "Eighty", "Ninety");
    
    if ($number == 0) return "Zero";
    if ($number < 20) return $ones[$number];
    if ($number < 100) return $tens[(int)($number / 10)] . (($number % 10 != 0) ? " " . $ones[$number % 10] : "");
    if ($number < 1000) return $ones[(int)($number / 100)] . " Hundred" . (($number % 100 != 0) ? " " . numberToWords($number % 100) : "");
    if ($number < 100000) return numberToWords((int)($number / 1000)) . " Thousand" . (($number % 1000 != 0) ? " " . numberToWords($number % 1000) : "");
    if ($number < 10000000) return numberToWords((int)($number / 100000)) . " Lakh" . (($number % 100000 != 0) ? " " . numberToWords($number % 100000) : "");
    return numberToWords((int)($number / 10000000)) . " Crore" . (($number % 10000000 != 0) ? " " . numberToWords($number % 10000000) : "");
}

$invoice_id = (int)($_GET['id'] ?? 0);

if ($invoice_id === 0) {
    die("Invalid invoice ID");
}

// Fetch invoice details
$sql = "
SELECT 
    i.*,
    cm.`client_name`,
    cm.`company_name`,
    cm.`contact_person`,
    cm.`email` as client_email,
    cm.`phone` as client_phone,
    cm.`address` as client_address,
    cm.`country` as client_country,
    cm.`gst_no`,
    
    j.`job_date`,
    j.`journal_issue`,
    j.`article`,
    j.`process_id`,
    j.`assigned_to`,
    j.`due_date`,
    j.`team_name`,
    j.`dispatch_date`
    
FROM `invoices` i
LEFT JOIN `client_master` cm ON i.`client_id` = cm.`client_id`
LEFT JOIN `jobs` j ON i.`job_id` = j.`job_id`
WHERE i.`invoice_id` = ?
";

$stmt = $mysqli->prepare($sql);
$stmt->bind_param("i", $invoice_id);
$stmt->execute();
$invoice = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$invoice) {
    die("Invoice not found");
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Invoice - <?= h($invoice['invoice_no']) ?></title>
    <style>
        @media print {
            body { margin: 0; padding: 0; }
            .no-print { display: none !important; }
            .container { width: 100% !important; max-width: none !important; }
            .card { border: none !important; box-shadow: none !important; }
        }
        body {
            font-family: Arial, sans-serif;
            line-height: 1.4;
            color: #333;
            background: white;
        }
        .container {
            max-width: 800px;
            margin: 0 auto;
            padding: 20px;
        }
        .invoice-header {
            border-bottom: 3px double #333;
            padding-bottom: 20px;
            margin-bottom: 30px;
        }
        .company-info {
            float: left;
            width: 50%;
        }
        .invoice-info {
            float: right;
            width: 45%;
            text-align: right;
        }
        .clear { clear: both; }
        .section {
            margin-bottom: 25px;
        }
        .section-title {
            background: #f8f9fa;
            padding: 8px 15px;
            border-left: 4px solid #007bff;
            margin-bottom: 15px;
            font-weight: bold;
        }
        .table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 20px;
        }
        .table th, .table td {
            border: 1px solid #ddd;
            padding: 10px;
            text-align: left;
        }
        .table th {
            background-color: #f8f9fa;
            font-weight: bold;
        }
        .amount-table {
            width: 60%;
            margin-left: auto;
        }
        .amount-table td {
            padding: 8px 15px;
        }
        .amount-table tr:last-child {
            background: #f8f9fa;
            font-weight: bold;
            font-size: 1.1em;
        }
        .text-right { text-align: right; }
        .text-center { text-align: center; }
        .mb-4 { margin-bottom: 25px; }
        .mt-4 { margin-top: 25px; }
        .signature-area {
            margin-top: 80px;
        }
        .signature-line {
            border-top: 1px solid #333;
            width: 200px;
            margin-top: 60px;
        }
        .footer {
            margin-top: 50px;
            padding-top: 20px;
            border-top: 1px solid #ddd;
            font-size: 0.9em;
            color: #666;
        }
    </style>
</head>
<body>
    <div class="container">
        <!-- Print Button -->
        <div class="no-print text-center mb-4">
            <button onclick="window.print()" class="btn btn-primary">
                <i class="fas fa-print"></i> Print Invoice
            </button>
            <button onclick="window.close()" class="btn btn-secondary">
                <i class="fas fa-times"></i> Close
            </button>
        </div>

        <!-- Header -->
        <div class="invoice-header">
            <div class="company-info">
                <h1 style="margin: 0; color: #2c3e50;">Your Company Name</h1>
                <p style="margin: 5px 0; color: #666;">
                    Company Address Line 1<br>
                    Company Address Line 2<br>
                    City, State - PIN Code<br>
                    Phone: +91 XXXXXXXXXX | Email: company@email.com<br>
                    GSTIN: XXXXXXXXXXXXXXX
                </p>
            </div>
            <div class="invoice-info">
                <h2 style="margin: 0; color: #2c3e50;">TAX INVOICE</h2>
                <p style="margin: 5px 0;">
                    <strong>Invoice No:</strong> <?= h($invoice['invoice_no']) ?><br>
                    <strong>Date:</strong> <?= formatDate($invoice['invoice_date']) ?><br>
                    <strong>Status:</strong> <?= h($invoice['status']) ?>
                </p>
            </div>
            <div class="clear"></div>
        </div>

        <!-- Bill To -->
        <div class="section">
            <div class="section-title">BILL TO</div>
            <div style="display: flex; justify-content: space-between;">
                <div style="width: 65%;">
                    <strong><?= h($invoice['client_name']) ?></strong><br>
                    <?= h($invoice['company_name']) ?><br>
                    <?= h($invoice['client_address']) ?><br>
                    <?= h($invoice['client_country']) ?><br>
                    <strong>GSTIN:</strong> <?= h($invoice['gst_no']) ?>
                </div>
                <div style="width: 30%;">
                    <strong>Job Details:</strong><br>
                    Article: <?= h($invoice['article']) ?><br>
                    Journal: <?= h($invoice['journal_issue']) ?><br>
                    Team: <?= h($invoice['team_name']) ?>
                </div>
            </div>
        </div>

        <!-- Invoice Items -->
        <div class="section">
            <div class="section-title">INVOICE DETAILS</div>
            <table class="table">
                <thead>
                    <tr>
                        <th width="5%">Sr No</th>
                        <th>Description of Services</th>
                        <th width="15%">Amount (₹)</th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td>1</td>
                        <td>
                            <strong>Professional Services</strong><br>
                            <small>For the month of <?= h($invoice['raised_month']) ?> - <?= h($invoice['process']) ?> services</small>
                        </td>
                        <td class="text-right"><?= number_format($invoice['total_amount'], 2) ?></td>
                    </tr>
                    
                    <?php if ($invoice['cgst_9_percent'] > 0): ?>
                    <tr>
                        <td>2</td>
                        <td>CGST @ 9%</td>
                        <td class="text-right"><?= number_format($invoice['cgst_9_percent'], 2) ?></td>
                    </tr>
                    <?php endif; ?>
                    
                    <?php if ($invoice['sgst_9_percent'] > 0): ?>
                    <tr>
                        <td>3</td>
                        <td>SGST @ 9%</td>
                        <td class="text-right"><?= number_format($invoice['sgst_9_percent'], 2) ?></td>
                    </tr>
                    <?php endif; ?>
                    
                    <?php if ($invoice['gst_18_percent'] > 0): ?>
                    <tr>
                        <td>4</td>
                        <td>GST @ 18%</td>
                        <td class="text-right"><?= number_format($invoice['gst_18_percent'], 2) ?></td>
                    </tr>
                    <?php endif; ?>
                    
                    <?php if ($invoice['tds_amount'] > 0): ?>
                    <tr>
                        <td>5</td>
                        <td>TDS Deduction @ <?= h($invoice['tds_deduction_percent']) ?>%</td>
                        <td class="text-right" style="color: red;">-<?= number_format($invoice['tds_amount'], 2) ?></td>
                    </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <!-- Amount Summary -->
        <div class="section">
            <table class="amount-table">
                <tr>
                    <td><strong>Total Amount:</strong></td>
                    <td class="text-right">₹<?= number_format($invoice['total_amount'], 2) ?></td>
                </tr>
                <?php if ($invoice['cgst_9_percent'] > 0): ?>
                <tr>
                    <td>CGST 9%:</td>
                    <td class="text-right">₹<?= number_format($invoice['cgst_9_percent'], 2) ?></td>
                </tr>
                <?php endif; ?>
                <?php if ($invoice['sgst_9_percent'] > 0): ?>
                <tr>
                    <td>SGST 9%:</td>
                    <td class="text-right">₹<?= number_format($invoice['sgst_9_percent'], 2) ?></td>
                </tr>
                <?php endif; ?>
                <?php if ($invoice['gst_18_percent'] > 0): ?>
                <tr>
                    <td>GST 18%:</td>
                    <td class="text-right">₹<?= number_format($invoice['gst_18_percent'], 2) ?></td>
                </tr>
                <?php endif; ?>
                <?php if ($invoice['tds_amount'] > 0): ?>
                <tr>
                    <td>TDS Deduction:</td>
                    <td class="text-right" style="color: red;">-₹<?= number_format($invoice['tds_amount'], 2) ?></td>
                </tr>
                <?php endif; ?>
                <tr>
                    <td><strong>Grand Total:</strong></td>
                    <td class="text-right"><strong>₹<?= number_format($invoice['grand_total'], 2) ?></strong></td>
                </tr>
            </table>
        </div>

        <!-- Amount in Words -->
        <div class="section">
            <p>
                <strong>Amount in Words:</strong><br>
                <?= numberToWords((int)$invoice['grand_total']) ?> Rupees Only
            </p>
        </div>

        <!-- Payment Information -->
        <div class="section">
            <div class="section-title">PAYMENT INFORMATION</div>
            <p>
                <strong>Bank Details:</strong><br>
                Account Name: Your Company Name<br>
                Bank Name: Your Bank Name<br>
                Account Number: XXXXXXXXXXXX<br>
                IFSC Code: XXXXXXXXXXX<br>
                Branch: Your Branch Name
            </p>
        </div>

        <!-- Remarks -->
        <?php if (!empty($invoice['remarks'])): ?>
        <div class="section">
            <div class="section-title">REMARKS</div>
            <p><?= h($invoice['remarks']) ?></p>
        </div>
        <?php endif; ?>

        <!-- Signature -->
        <div class="signature-area">
            <div style="float: right; text-align: center;">
                <div class="signature-line"></div>
                <p>Authorized Signatory</p>
            </div>
            <div class="clear"></div>
        </div>

        <!-- Footer -->
        <div class="footer text-center">
            <p>
                This is a computer generated invoice. No signature required.<br>
                For any queries, please contact: accounts@yourcompany.com | Phone: +91 XXXXXXXXXX
            </p>
        </div>
    </div>

    <script>
        // Auto-print when page loads
        window.onload = function() {
            setTimeout(function() {
                window.print();
            }, 500);
        };
    </script>
</body>
</html> 