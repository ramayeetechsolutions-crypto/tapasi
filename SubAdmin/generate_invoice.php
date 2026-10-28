<?php
require 'db.php';

$invoice_id = (int)($_GET['invoice_id'] ?? 0);
//$invoice_month = $_GET['invoice_month'] ?? date('Y-m');
// 1. Get the base month (e.g., '2025-10')
$invoice_month = $_GET['invoice_month'] ?? date('Y-m');

// 2. Calculate the next month using strtotime()
// The '+1 month' correctly handles year rollovers (e.g., '2025-12' -> '2026-01')
$next_month_timestamp = strtotime($invoice_month . '-01 +1 month');

// 3. Format the new timestamp back into 'YYYY-MM' format
$invoice_month_plus_one = date('Y-m', $next_month_timestamp);
if ($invoice_id <= 0) {
    die("Invalid Invoice ID");
}

// --- Fetch Invoice ---
$sqlInvoice = "SELECT * FROM client_invoice WHERE invoice_id = $invoice_id AND DATE_FORMAT(invoice_date, '%Y-%m') = '$invoice_month_plus_one'";
//echo $sqlInvoice;
//die;
$invoice = $mysqli->query($sqlInvoice)->fetch_assoc();
if (!$invoice) die("Invoice not found");

// --- Fetch Client ---
$sqlClient = "SELECT * FROM client_master WHERE client_id = " . (int)$invoice['client_id'];
$client_id=$invoice['client_id'];
$client = $mysqli->query($sqlClient)->fetch_assoc();

// --- Fetch Company (your company) ---
$sqlCompany = "SELECT * FROM company_master LIMIT 1";
$company = $mysqli->query($sqlCompany)->fetch_assoc();
//echo "Client_id".$client_id;
//$client_id = (int)($_GET['client_id'] ?? 0); // or from POST
$description = '';

if ($client_id > 0) {
    $stmt = $mysqli->prepare("
        SELECT job_id, job_date, journal_issue, article, process_id, assigned_to, 
               assigned_ce, assigned_le, assigned_pag, assigned_qc, assigned_xml, due_date
        FROM jobs
        WHERE client_id = ?
        ORDER BY job_date ASC
    ");
    $stmt->bind_param("i", $client_id);
    $stmt->execute();
    $result = $stmt->get_result();

    $jobs = [];
    while ($row = $result->fetch_assoc()) {
        // You can format the description as you like
        $jobs[] = "{$row['journal_issue']},{$row['article']}";
    }

    // Combine all jobs into a single string
 //   $description = implode("\n", $jobs);

    $stmt->close();
}

// Now $description contains all job details for that client
//echo nl2br(htmlspecialchars($description)); // for testing

?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Invoice <?= htmlspecialchars($invoice['invoice_no']) ?></title>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
<style>
body { font-size: 14px; }
.invoice-box {
    border: 1px solid #ddd;
    padding: 20px;
    margin: 20px auto;
    max-width: 900px;
    background: #fff;
}
h4 { margin-bottom: 0; }
.table th, .table td { vertical-align: middle; }

@media print {
    .no-print {
        display: none !important;
    }
}


</style>
</head>
<body>
<div class="invoice-box">
<div class="d-flex justify-content-between mb-3 no-print">
    <!-- Back button -->
    <a href="client_master.php" 
       class="btn btn-secondary">
       ← Back to Client
    </a>

    <!-- Print button -->
    <button type="button" class="btn btn-primary" onclick="window.print()">
        🖨️ Print Invoice
    </button>
</div>

    <!-- Header -->
    <div class="row mb-4">
        <div class="col-6">
            <h4><?= htmlspecialchars($company['company_name']) ?></h4>
            <p>
                <?= nl2br(htmlspecialchars($company['company_address'])) ?><br>
                PAN: <?= htmlspecialchars($company['pan_number']) ?><br>
                GST: <?= htmlspecialchars($company['gst_no'] ?? '') ?>
            </p>
        </div>
        <div class="col-6 text-end">
            <h5>INVOICE</h5>
            <p>
                <b>No:</b> <?= htmlspecialchars($invoice['invoice_no']) ?><br>
                <b>Date:</b> <?= date('d-M-Y', strtotime($invoice['invoice_date'])) ?><br>
            </p>
        </div>
    </div>

    <!-- Bill To -->
    <div class="row mb-4">
        <div class="col-6">
            <b>Bill To:</b><br>
            <?= htmlspecialchars($client['client_name']) ?><br>
            <?= htmlspecialchars($client['company_name']) ?><br>
            <?= nl2br(htmlspecialchars($client['address'])) ?><br>
            <?= htmlspecialchars($client['email']) ?> | <?= htmlspecialchars($client['phone']) ?><br>
            GST: <?= htmlspecialchars($client['gst_no']) ?>
        </div>
    </div>

    <!-- Invoice Table -->
    <table class="table table-bordered">
        <thead class="table-light">
            <tr>
                <th>Item</th>
                <th>Description</th>
                <th>Pages</th>
                <th>Price/Page</th>
                <th>Total</th>
            </tr>
        </thead>
        <tbody>
            <?php
            $services = ['CE'=>'ce','PAG'=>'pag','XML'=>'xml','MATH'=>'math','REF/IMG'=>'ref_img'];
            $i=0;
            foreach ($services as $label=>$field):
                $i=$i+1;
                if ($invoice[$field] > 0):
                    $line_total = $invoice[$field] * $invoice['price_per_page'];
            ?>
            <tr>
                <td><?= $label ?></td>
                <td><?= $jobs[$i]?></td>
                <td class="text-end"><?= number_format($invoice[$field]) ?></td>
                <td class="text-end"><?= number_format($invoice['price_per_page'], 2) ?></td>
                <td class="text-end"><?= number_format($line_total, 2) ?></td>
            </tr>
            <?php endif; endforeach; ?>
        </tbody>
    </table>

    <!-- Totals -->
    <div class="row">
            <table class="table">
                <tr>
                    <td></td>
                    <td></td>
                    <td></td>
                    <th class="text-end">Subtotal:</th>
                    <td class="text-end"><?= number_format($invoice['total'], 2) ?></td>
                </tr>
                <tr>
                    <td></td>
                    <td></td>
                    <td></td>

                    <th class="text-end">Tax:</th>
                    <td class="text-end"><?= number_format($invoice['tax'], 2) ?></td>
                </tr>
                <tr>
                    <td></td>
                    <td></td>
                    <td><center><b>Payment: <?php echo $invoice['tat'];?></b></center></td>

                    <th class="text-end">Net Total:</th>
                    <td class="text-end"><b><?= number_format($invoice['net_total'], 2) ?></b></td>
                </tr>
            </table>
       
    <!-- Footer -->
    <div class="row mt-5">
        <div class="col-6">
            <b>Bank Details</b><br>
            Bank: <?= htmlspecialchars($company['bank_name']) ?><br>
            A/C Name: <?= htmlspecialchars($company['bank_account_name']) ?><br>
            A/C No: <?= htmlspecialchars($company['bank_account_number']) ?><br>
            IFSC: <?= htmlspecialchars($company['bank_ifsc_code']) ?><br>
            SWIFT: <?= htmlspecialchars($company['bank_swift_code']) ?><br>
        </div>
        <div class="col-6 text-end">
            <p>For <b><?= htmlspecialchars($company['company_name']) ?></b></p>
            <br><br><br>
            <p>Authorised Signatory</p>
            <p><b><h6>Key Account Manager</h6></b></p>
        </div>
    </div>

</div>

<!--script>
window.print(); // auto open print dialog
</script-->
</body>
</html>
