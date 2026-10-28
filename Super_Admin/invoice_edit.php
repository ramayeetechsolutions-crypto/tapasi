<?php
require 'db.php';
if (session_status() === PHP_SESSION_NONE) { 
    session_start(); 
}

// Helper functions
function h($s) { 
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); 
}

$invoice_id = (int)($_GET['id'] ?? 0);

if ($invoice_id === 0) {
    header("Location: invoices.php?msg=Invalid invoice ID&type=danger");
    exit;
}

// Fetch invoice details
$sql = "
SELECT 
    i.*,
    cm.`client_name`,
    cm.`company_name`,
    j.`article`,
    j.`journal_issue`
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

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $invoice_no = $_POST['invoice_no'] ?? '';
    $invoice_date = $_POST['invoice_date'] ?? '';
    $total_amount = (float)($_POST['total_amount'] ?? 0);
    $cgst_9_percent = (float)($_POST['cgst_9_percent'] ?? 0);
    $sgst_9_percent = (float)($_POST['sgst_9_percent'] ?? 0);
    $gst_18_percent = (float)($_POST['gst_18_percent'] ?? 0);
    $tds_deduction_percent = (float)($_POST['tds_deduction_percent'] ?? 0);
    $tds_amount = (float)($_POST['tds_amount'] ?? 0);
    $final_amount_after_tds = (float)($_POST['final_amount_after_tds'] ?? 0);
    $grand_total = (float)($_POST['grand_total'] ?? 0);
    $status = $_POST['status'] ?? 'Pending';
    $remarks = $_POST['remarks'] ?? '';
    $worked_month = $_POST['worked_month'] ?? '';
    $raised_month = $_POST['raised_month'] ?? '';
    
    $update_sql = "
        UPDATE invoices SET 
            invoice_no = ?,
            invoice_date = ?,
            total_amount = ?,
            cgst_9_percent = ?,
            sgst_9_percent = ?,
            gst_18_percent = ?,
            tds_deduction_percent = ?,
            tds_amount = ?,
            final_amount_after_tds = ?,
            grand_total = ?,
            status = ?,
            remarks = ?,
            worked_month = ?,
            raised_month = ?
        WHERE invoice_id = ?
    ";
    
    $stmt = $mysqli->prepare($update_sql);
    $stmt->bind_param(
        "ssddddddddsssi",
        $invoice_no,
        $invoice_date,
        $total_amount,
        $cgst_9_percent,
        $sgst_9_percent,
        $gst_18_percent,
        $tds_deduction_percent,
        $tds_amount,
        $final_amount_after_tds,
        $grand_total,
        $status,
        $remarks,
        $worked_month,
        $raised_month,
        $invoice_id
    );
    
    if ($stmt->execute()) {
        header("Location: invoice_view.php?id=$invoice_id&msg=Invoice updated successfully&type=success");
        exit;
    } else {
        $error = "Error updating invoice: " . $stmt->error;
    }
    $stmt->close();
}

// Get clients and jobs for dropdowns
$clients = $mysqli->query("SELECT client_id, client_name, company_name FROM client_master WHERE status = 'Active' ORDER BY client_name");
$jobs = $mysqli->query("SELECT job_id, article, journal_issue FROM jobs ORDER BY job_date DESC");
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit Invoice - <?= h($invoice['invoice_no']) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body {
            background-color: #f8f9fa;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }
        .main-content {
            padding: 20px;
            max-width: 1200px;
            margin: 0 auto;
        }
        .card {
            box-shadow: 0 0.125rem 0.25rem rgba(0, 0, 0, 0.075);
            border: 1px solid rgba(0, 0, 0, 0.125);
            margin-bottom: 20px;
        }
        .calculation-box {
            background: #f8f9fa;
            border: 1px solid #dee2e6;
            border-radius: 5px;
            padding: 15px;
            margin-bottom: 20px;
        }
    </style>
</head>
<body>
    <?php if (file_exists('header.php')) require 'header.php'; ?>
    
    <div class="main-content">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h4><i class="fas fa-edit me-2"></i>Edit Invoice - <?= h($invoice['invoice_no']) ?></h4>
            <div class="btn-group">
                <a href="invoice_view.php?id=<?= $invoice_id ?>" class="btn btn-info">
                    <i class="fas fa-eye me-1"></i> View Invoice
                </a>
                <a href="invoices.php" class="btn btn-secondary">
                    <i class="fas fa-arrow-left me-1"></i> Back to List
                </a>
            </div>
        </div>

        <?php if (isset($error)): ?>
        <div class="alert alert-danger alert-dismissible fade show" role="alert">
            <?= h($error) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <?php endif; ?>

        <form method="post" autocomplete="off">
            <div class="row">
                <!-- Basic Information -->
                <div class="col-md-6">
                    <div class="card">
                        <div class="card-header bg-primary text-white">
                            <h5 class="mb-0"><i class="fas fa-info-circle me-2"></i>Basic Information</h5>
                        </div>
                        <div class="card-body">
                            <div class="mb-3">
                                <label for="invoice_no" class="form-label">Invoice Number *</label>
                                <input type="text" class="form-control" id="invoice_no" name="invoice_no" 
                                       value="<?= h($invoice['invoice_no']) ?>" required>
                            </div>
                            
                            <div class="mb-3">
                                <label for="invoice_date" class="form-label">Invoice Date *</label>
                                <input type="date" class="form-control" id="invoice_date" name="invoice_date" 
                                       value="<?= h($invoice['invoice_date']) ?>" required>
                            </div>
                            
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label for="worked_month" class="form-label">Worked Month</label>
                                        <select class="form-select" id="worked_month" name="worked_month">
                                            <option value="">Select Month</option>
                                            <?php
                                            $months = ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];
                                            foreach($months as $month): ?>
                                                <option value="<?= h($month) ?>" <?= $invoice['worked_month'] === $month ? 'selected' : '' ?>>
                                                    <?= h($month) ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label for="raised_month" class="form-label">Raised Month</label>
                                        <select class="form-select" id="raised_month" name="raised_month">
                                            <option value="">Select Month</option>
                                            <?php foreach($months as $month): ?>
                                                <option value="<?= h($month) ?>" <?= $invoice['raised_month'] === $month ? 'selected' : '' ?>>
                                                    <?= h($month) ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="mb-3">
                                <label for="status" class="form-label">Status</label>
                                <select class="form-select" id="status" name="status">
                                    <option value="Pending" <?= $invoice['status'] === 'Pending' ? 'selected' : '' ?>>Pending</option>
                                    <option value="Paid" <?= $invoice['status'] === 'Paid' ? 'selected' : '' ?>>Paid</option>
                                    <option value="Cancelled" <?= $invoice['status'] === 'Cancelled' ? 'selected' : '' ?>>Cancelled</option>
                                </select>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Amount Information -->
                <div class="col-md-6">
                    <div class="card">
                        <div class="card-header bg-success text-white">
                            <h5 class="mb-0"><i class="fas fa-calculator me-2"></i>Amount Information</h5>
                        </div>
                        <div class="card-body">
                            <div class="mb-3">
                                <label for="total_amount" class="form-label">Total Amount (₹) *</label>
                                <input type="number" class="form-control" id="total_amount" name="total_amount" 
                                       value="<?= h($invoice['total_amount']) ?>" step="0.01" min="0" required>
                            </div>
                            
                            <div class="row">
                                <div class="col-md-4">
                                    <div class="mb-3">
                                        <label for="cgst_9_percent" class="form-label">CGST 9% (₹)</label>
                                        <input type="number" class="form-control" id="cgst_9_percent" name="cgst_9_percent" 
                                               value="<?= h($invoice['cgst_9_percent']) ?>" step="0.01" min="0">
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <div class="mb-3">
                                        <label for="sgst_9_percent" class="form-label">SGST 9% (₹)</label>
                                        <input type="number" class="form-control" id="sgst_9_percent" name="sgst_9_percent" 
                                               value="<?= h($invoice['sgst_9_percent']) ?>" step="0.01" min="0">
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <div class="mb-3">
                                        <label for="gst_18_percent" class="form-label">GST 18% (₹)</label>
                                        <input type="number" class="form-control" id="gst_18_percent" name="gst_18_percent" 
                                               value="<?= h($invoice['gst_18_percent']) ?>" step="0.01" min="0">
                                    </div>
                                </div>
                            </div>
                            
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label for="tds_deduction_percent" class="form-label">TDS Deduction %</label>
                                        <input type="number" class="form-control" id="tds_deduction_percent" name="tds_deduction_percent" 
                                               value="<?= h($invoice['tds_deduction_percent']) ?>" step="0.01" min="0" max="100">
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label for="tds_amount" class="form-label">TDS Amount (₹)</label>
                                        <input type="number" class="form-control" id="tds_amount" name="tds_amount" 
                                               value="<?= h($invoice['tds_amount']) ?>" step="0.01" min="0">
                                    </div>
                                </div>
                            </div>
                            
                            <div class="mb-3">
                                <label for="final_amount_after_tds" class="form-label">Final Amount After TDS (₹)</label>
                                <input type="number" class="form-control" id="final_amount_after_tds" name="final_amount_after_tds" 
                                       value="<?= h($invoice['final_amount_after_tds']) ?>" step="0.01" min="0">
                            </div>
                            
                            <div class="mb-3">
                                <label for="grand_total" class="form-label">Grand Total (₹) *</label>
                                <input type="number" class="form-control" id="grand_total" name="grand_total" 
                                       value="<?= h($invoice['grand_total']) ?>" step="0.01" min="0" required>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Additional Information -->
            <div class="card">
                <div class="card-header bg-info text-white">
                    <h5 class="mb-0"><i class="fas fa-sticky-note me-2"></i>Additional Information</h5>
                </div>
                <div class="card-body">
                    <div class="mb-3">
                        <label for="remarks" class="form-label">Remarks</label>
                        <textarea class="form-control" id="remarks" name="remarks" rows="3"><?= h($invoice['remarks']) ?></textarea>
                    </div>
                </div>
            </div>

            <div class="d-flex gap-2 mt-4">
                <button type="submit" class="btn btn-success">
                    <i class="fas fa-save me-1"></i> Update Invoice
                </button>
                <a href="invoice_view.php?id=<?= $invoice_id ?>" class="btn btn-secondary">Cancel</a>
            </div>
        </form>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Auto-calculate amounts
        document.addEventListener('DOMContentLoaded', function() {
            const totalAmount = document.getElementById('total_amount');
            const tdsPercent = document.getElementById('tds_deduction_percent');
            const tdsAmount = document.getElementById('tds_amount');
            const finalAmount = document.getElementById('final_amount_after_tds');
            const grandTotal = document.getElementById('grand_total');
            
            function calculateAmounts() {
                const total = parseFloat(totalAmount.value) || 0;
                const tdsPer = parseFloat(tdsPercent.value) || 0;
                
                // Calculate TDS amount
                const tdsAmt = (total * tdsPer) / 100;
                if (tdsAmount) tdsAmount.value = tdsAmt.toFixed(2);
                
                // Calculate final amount after TDS
                const finalAmt = total - tdsAmt;
                if (finalAmount) finalAmount.value = finalAmt.toFixed(2);
                
                // Update grand total
                if (grandTotal) grandTotal.value = finalAmt.toFixed(2);
            }
            
            if (totalAmount) totalAmount.addEventListener('input', calculateAmounts);
            if (tdsPercent) tdsPercent.addEventListener('input', calculateAmounts);
        });
    </script>
</body>
</html>