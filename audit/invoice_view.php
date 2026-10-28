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

$invoice_id = (int)($_GET['id'] ?? 0);

if ($invoice_id === 0) {
    header("Location: invoices.php?msg=Invalid invoice ID&type=danger");
    exit;
}

// Fetch invoice details with client and job information
$sql = "
SELECT 
    i.*,
    cm.`client_name`,
    cm.`company_name`,
    cm.`contact_person`,
    cm.`email` as client_email,
    cm.`general_email`,
    cm.`invoice_email`,
    cm.`production_email`,
    cm.`phone` as client_phone,
    cm.`address` as client_address,
    cm.`country` as client_country,
    cm.`gst_no`,
    cm.`cgst` as client_cgst,
    cm.`igst` as client_igst,
    cm.`tds_percentage` as client_tds_percentage,
    cm.`paypal_email`,
    cm.`paypal_percentage`,
    cm.`preferred_communication`,
    cm.`preferred_language`,
    cm.`word_rate`,
    cm.`page_rate`,
    cm.`gst_per`,
    cm.`currency`,
    cm.`math_rate`,
    cm.`ref_rate`,
    cm.`additional_mobile`,
    cm.`additional_address`,
    
    j.`job_date`,
    j.`journal_issue`,
    j.`article`,
    j.`process_id`,
    j.`assigned_to`,
    j.`assigned_ce`,
    j.`assigned_le`,
    j.`assigned_pag`,
    j.`assigned_qc`,
    j.`assigned_xml`,
    j.`due_date`,
    j.`team_name`,
    j.`due_date_ce`,
    j.`due_date_le`,
    j.`due_date_pag`,
    j.`due_date_qc`,
    j.`file_path`,
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
    header("Location: invoices.php?msg=Invoice not found&type=danger");
    exit;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>View Invoice - <?= h($invoice['invoice_no']) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body {
            background-color: #f8f9fa;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }
        .main-content {
            padding: 20px;
            max-width: 1400px;
            margin: 0 auto;
        }
        .card {
            box-shadow: 0 0.125rem 0.25rem rgba(0, 0, 0, 0.075);
            border: 1px solid rgba(0, 0, 0, 0.125);
            margin-bottom: 20px;
        }
        .invoice-header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 30px;
            border-radius: 10px;
            margin-bottom: 30px;
        }
        .amount-box {
            background: #f8f9fa;
            border: 2px solid #e9ecef;
            border-radius: 8px;
            padding: 15px;
            text-align: center;
        }
        .amount-label {
            font-size: 0.9em;
            color: #6c757d;
            margin-bottom: 5px;
        }
        .amount-value {
            font-size: 1.3em;
            font-weight: bold;
        }
        .text-success { color: #28a745 !important; }
        .text-danger { color: #dc3545 !important; }
        .text-info { color: #17a2b8 !important; }
        .status-badge {
            font-size: 0.9em;
            padding: 8px 15px;
        }
    </style>
</head>
<body>
    <?php if (file_exists('header.php')) require 'header.php'; ?>
    
    <div class="main-content">
        <!-- Header -->
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h4><i class="fas fa-file-invoice me-2"></i>View Invoice</h4>
            <div class="btn-group">
                <!--a href="invoice_edit.php?id=<?= $invoice_id ?>" class="btn btn-warning">
                    <i class="fas fa-edit me-1"></i> Edit Invoice
                </a-->
                <!--a href="invoice_print.php?id=<?= $invoice_id ?>" class="btn btn-primary" target="_blank">
                    <i class="fas fa-print me-1"></i> Print Invoice
                </a-->
                <a href="invoices.php" class="btn btn-secondary">
                    <i class="fas fa-arrow-left me-1"></i> Back to List
                </a>
            </div>
        </div>

        <!-- Invoice Header -->
        <div class="invoice-header">
            <div class="row align-items-center">
                <div class="col-md-6">
                    <h2 class="mb-1">INVOICE</h2>
                    <h3 class="mb-0"><?= h($invoice['invoice_no']) ?></h3>
                    <p class="mb-0 mt-2">Date: <?= formatDate($invoice['invoice_date']) ?></p>
                </div>
                <div class="col-md-6 text-end">
                    <span class="btn btn-light status-badge <?= $invoice['status'] === 'Paid' ? 'btn-success' : ($invoice['status'] === 'Pending' ? 'btn-warning' : 'btn-danger') ?>">
                        Status: <?= h($invoice['status']) ?>
                    </span>
                </div>
            </div>
        </div>

        <div class="row">
            <!-- Left Column - Client & Job Details -->
            <div class="col-md-8">
                <!-- Client Information -->
                <div class="card">
                    <div class="card-header bg-primary text-white">
                        <h5 class="mb-0"><i class="fas fa-building me-2"></i>Client Information</h5>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <div class="col-md-6">
                                <strong>Client Name:</strong> <?= h($invoice['client_name']) ?><br>
                                <strong>Company:</strong> <?= h($invoice['company_name']) ?><br>
                                <strong>Contact Person:</strong> <?= h($invoice['contact_person']) ?><br>
                                <strong>Email:</strong> <?= h($invoice['client_email']) ?><br>
                                <strong>Phone:</strong> <?= h($invoice['client_phone']) ?>
                            </div>
                            <div class="col-md-6">
                                <strong>Address:</strong> <?= h($invoice['client_address']) ?><br>
                                <strong>Country:</strong> <?= h($invoice['client_country']) ?><br>
                                <strong>GST No:</strong> <?= h($invoice['gst_no']) ?><br>
                                <strong>TDS %:</strong> <?= h($invoice['client_tds_percentage']) ?>%
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Job Information -->
                <div class="card">
                    <div class="card-header bg-info text-white">
                        <h5 class="mb-0"><i class="fas fa-briefcase me-2"></i>Job Information</h5>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <div class="col-md-6">
                                <strong>Article:</strong> <?= h($invoice['article']) ?><br>
                                <strong>Journal Issue:</strong> <?= h($invoice['journal_issue']) ?><br>
                                <strong>Process:</strong> <?= h($invoice['process']) ?><br>
                                <strong>Team:</strong> <?= h($invoice['team_name']) ?>
                            </div>
                            <div class="col-md-6">
                                <strong>Job Date:</strong> <?= formatDate($invoice['job_date']) ?><br>
                                <strong>Due Date:</strong> <?= formatDate($invoice['due_date']) ?><br>
                                <strong>Dispatch Date:</strong> <?= formatDate($invoice['dispatch_date']) ?><br>
                                <strong>Assigned To:</strong> <?= h($invoice['assigned_to']) ?>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Additional Information -->
                <div class="card">
                    <div class="card-header bg-secondary text-white">
                        <h5 class="mb-0"><i class="fas fa-info-circle me-2"></i>Additional Information</h5>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <div class="col-md-6">
                                <strong>Worked Month:</strong> <?= h($invoice['worked_month']) ?><br>
                                <strong>Raised Month:</strong> <?= h($invoice['raised_month']) ?><br>
                                <strong>Country:</strong> <?= h($invoice['country']) ?><br>
                                <strong>TAT:</strong> <?= h($invoice['tat']) ?> days
                            </div>
                            <div class="col-md-6">
                                <strong>Created By:</strong> <?= h($invoice['created_by']) ?><br>
                                <strong>Created Date:</strong> <?= formatDate($invoice['created_date']) ?><br>
                                <strong>Remarks:</strong> <?= h($invoice['remarks']) ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Right Column - Financial Information -->
            <div class="col-md-4">
                <!-- Amount Summary -->
                <div class="card">
                    <div class="card-header bg-success text-white">
                        <h5 class="mb-0"><i class="fas fa-calculator me-2"></i>Financial Summary</h5>
                    </div>
                    <div class="card-body">
                        <div class="amount-box mb-3">
                            <div class="amount-label">Total Amount</div>
                            <div class="amount-value text-info"><?= formatCurrency($invoice['total_amount']) ?></div>
                        </div>

                        <div class="amount-box mb-3">
                            <div class="amount-label">CGST 9%</div>
                            <div class="amount-value"><?= formatCurrency($invoice['cgst_9_percent']) ?></div>
                        </div>

                        <div class="amount-box mb-3">
                            <div class="amount-label">SGST 9%</div>
                            <div class="amount-value"><?= formatCurrency($invoice['sgst_9_percent']) ?></div>
                        </div>

                        <div class="amount-box mb-3">
                            <div class="amount-label">GST 18%</div>
                            <div class="amount-value"><?= formatCurrency($invoice['gst_18_percent']) ?></div>
                        </div>

                        <div class="amount-box mb-3">
                            <div class="amount-label">TDS Deduction (<?= h($invoice['tds_deduction_percent']) ?>%)</div>
                            <div class="amount-value text-danger"><?= formatCurrency($invoice['tds_amount']) ?></div>
                        </div>

                        <div class="amount-box mb-3 bg-light">
                            <div class="amount-label">Final Amount After TDS</div>
                            <div class="amount-value text-info"><?= formatCurrency($invoice['final_amount_after_tds']) ?></div>
                        </div>

                        <div class="amount-box bg-warning">
                            <div class="amount-label">Grand Total</div>
                            <div class="amount-value text-success"><?= formatCurrency($invoice['grand_total']) ?></div>
                        </div>
                    </div>
                </div>

                <!-- Quick Actions -->
                <div class="card">
                    <div class="card-header bg-dark text-white">
                        <h5 class="mb-0"><i class="fas fa-bolt me-2"></i>Quick Actions</h5>
                    </div>
                    <div class="card-body">
                        <div class="d-grid gap-2">
                            <form method="post" action="invoices.php" class="d-grid">
                                <input type="hidden" name="action" value="update_status">
                                <input type="hidden" name="invoice_id" value="<?= $invoice_id ?>">
                                <select name="status" class="form-select mb-2" onchange="this.form.submit()">
                                    <option value="Pending" <?= $invoice['status'] === 'Pending' ? 'selected' : '' ?>>Mark as Pending</option>
                                    <option value="Paid" <?= $invoice['status'] === 'Paid' ? 'selected' : '' ?>>Mark as Paid</option>
                                    <option value="Cancelled" <?= $invoice['status'] === 'Cancelled' ? 'selected' : '' ?>>Mark as Cancelled</option>
                                </select>
                            </form>
                            <!--a href="invoice_edit.php?id=<?= $invoice_id ?>" class="btn btn-warning">
                                <i class="fas fa-edit me-1"></i> Edit Invoice
                            </a-->
                            <!--a href="invoice_print.php?id=<?= $invoice_id ?>" class="btn btn-primary" target="_blank">
                                <i class="fas fa-print me-1"></i> Print Invoice
                            </a-->
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>