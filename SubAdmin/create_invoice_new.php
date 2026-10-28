<?php
require 'db.php';
if (session_status() === PHP_SESSION_NONE) { session_start(); }

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function formatDate($d){ return (!empty($d) && $d!='0000-00-00')?date('d-M-Y', strtotime($d)):'—'; }
function formatCurrency($amount){ return 'USD ' . number_format((float)$amount, 2); }


$invoice_data = null;
$errors = [];
$invoice_id_for_print = 0;

// --- Get client_id and invoice_month from GET
$client_id = isset($_GET['client_id']) ? (int)$_GET['client_id'] : 0;
// Expect invoice_month in YYYY-MM format, e.g., ?client_id=1&invoice_month=2023-10
$invoice_month_str = isset($_GET['invoice_month']) ? $_GET['invoice_month'] : date('Y-m');

// Validate invoice_month_str
if (!preg_match('/^\d{4}-\d{2}$/', $invoice_month_str)) {
    $errors[] = "Invalid invoice_month format. Expected YYYY-MM.";
}

if ($client_id <= 0) {
    $errors[] = "A valid Client ID is required to generate an invoice.";
}

if (empty($errors)) {
    // Determine the invoice date (e.g., last day of the month)
    $invoice_date_obj = DateTime::createFromFormat('Y-m-d', $invoice_month_str . '-01');
    if (!$invoice_date_obj) {
         $errors[] = "Could not parse invoice month.";
    } else {
        // Set invoice_date to the first day of the month for the invoice record itself (or last if preferred)
        // For the *query range*, we'll use first and last day.
        $query_month_start_obj = clone $invoice_date_obj; // Start of month
        $query_month_end_obj = clone $invoice_date_obj;
        $query_month_end_obj->modify('last day of this month');

        $invoice_date = $query_month_end_obj->format('Y-m-d'); // The actual date of the invoice
        $display_month_name = $query_month_end_obj->format('F Y');

        $month_start_for_query = $query_month_start_obj->format('Y-m-d 00:00:00');
        $month_end_for_query = $query_month_end_obj->format('Y-m-d 23:59:59');
    }
}

if (empty($errors)) {
    // 1. Fetch Client Details
    $stmt = $mysqli->prepare("SELECT * FROM client_master WHERE client_id = ?");
    if (!$stmt) die("Prepare failed (client): ".$mysqli->error);
    $stmt->bind_param("i", $client_id);
    $stmt->execute();
    $client = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$client) {
        $errors[] = "Client with ID " . $client_id . " not found.";
    }
}

if (empty($errors)) {
    // 2. Fetch Company Details
    $company = $mysqli->query("SELECT * FROM company_master ORDER BY id DESC LIMIT 1");
    $company = $company ? $company->fetch_assoc() : [];
    if (!$company) {
        $errors[] = "Company details not found.";
    }
}

$jobs_for_invoice = [];
$total_calculated_amount = 0;

if (empty($errors)) {
    // 3. Find ALL completed jobs for this client in the specified month
    // Use the refined dates for the query
    $find_jobs_sql = "
        SELECT j.job_id, j.job_date, j.journal_issue, j.article, jm.job_name AS process_name, jm.rate, j.client_id
        FROM jobs j
        LEFT JOIN job_master jm ON jm.job_id = j.process_id
        WHERE j.client_id = ?
        AND j.job_date BETWEEN ? AND ?
        AND (
            EXISTS (SELECT 1 FROM ce WHERE ce.job_id = j.job_id AND ce.status = 'Completed')
            OR EXISTS (SELECT 1 FROM le WHERE le.job_id = j.job_id AND le.status = 'Completed')
            OR EXISTS (SELECT 1 FROM pag WHERE pag.job_id = j.job_id AND pag.status = 'Completed')
            OR EXISTS (SELECT 1 FROM qc WHERE qc.job_id = j.job_id AND qc.status = 'Completed')
        )
        ORDER BY j.job_date ASC, j.job_id ASC";

    $stmt = $mysqli->prepare($find_jobs_sql);
    if (!$stmt) die("Prepare failed (find monthly jobs): ".$mysqli->error);
    $stmt->bind_param("iss", $client_id, $month_start_for_query, $month_end_for_query); // Use the new variables
    $stmt->execute();
    $monthly_jobs_res = $stmt->get_result();
    $stmt->close();
    if ($monthly_jobs_res->num_rows === 0) {
        $errors[] = "No completed jobs found for client ID " . $client_id . " in " . $display_month_name . ".";
    } else {
        while($job_row = $monthly_jobs_res->fetch_assoc()) {
            $job_id = (int)$job_row['job_id'];
            $qty = 0; // Default qty

            // Try to fetch pages from pag table for this specific job
            $stmt_pages = $mysqli->prepare("SELECT pages FROM pag WHERE job_id = ? ORDER BY id DESC LIMIT 1");
            if ($stmt_pages) {
                $stmt_pages->bind_param("i", $job_id);
                $stmt_pages->execute();
                $row_pages = $stmt_pages->get_result()->fetch_assoc();
                $stmt_pages->close();
                if ($row_pages && isset($row_pages['pages'])) $qty = (int)$row_pages['pages'];
            }
            if ($qty <= 0) $qty = 1; // Default to 1 if no pages or invalid

            $price_per_unit = (float)($job_row['rate'] ?? 0.00);
            $amount_item = round($qty * $price_per_unit, 2);
            $total_calculated_amount += $amount_item;

            $jobs_for_invoice[] = [
                'job_id' => $job_id,
                'description' => $job_row['article'] ?: $job_row['journal_issue'] ?: ('Job #'.$job_id),
                'process_name' => $job_row['process_name'],
                'sac_code' => '998319', // Default SAC code for now, can be specific per job type
                'qty' => $qty,
                'price_per_unit' => $price_per_unit,
                'amount' => $amount_item
            ];
        }
    }
}


// --- Proceed to save/display if no errors and jobs were found
if (empty($errors) && !empty($jobs_for_invoice)) {
    // 4. Default/inferred values for invoice fields
    $invoice_no = 'INV-' . $client_id . '-' . $invoice_date_obj->format('Ym');
    $sac_code_master = '998319'; // Main SAC code if all items share one
    $notes = "Invoice for services rendered during " . $display_month_name . ". Details per job below.";
    $payment_terms = 'Within 15 days';

    // --- Check if an invoice already exists for this client for this month
    $existing_invoice_id = 0;
    // Check by client_id and the month of invoice_date
    $stmt = $mysqli->prepare("SELECT invoice_id, invoice_no, invoice_date, total_amount FROM invoices WHERE client_id = ? AND DATE_FORMAT(invoice_date, '%Y-%m') = ? LIMIT 1");
    if ($stmt) {
        $stmt->bind_param("is", $client_id, $invoice_month_str);
        $stmt->execute();
        $res = $stmt->get_result();
        if ($res->num_rows > 0) {
            $existing_inv_data = $res->fetch_assoc();
            $existing_invoice_id = $existing_inv_data['invoice_id'];
        }
        $stmt->close();
    }


    if ($existing_invoice_id > 0) {
        // Invoice already exists, fetch its details to display
        $invoice_id_for_print = $existing_invoice_id;
        $errors[] = "Invoice for client ID " . $client_id . " for " . $display_month_name . " already exists (Invoice ID: " . $existing_invoice_id . "). Displaying existing invoice.";

        // Fetch the existing invoice and its items
        $stmt = $mysqli->prepare("SELECT * FROM invoices WHERE invoice_id = ?");
        if (!$stmt) die("Prepare failed (fetch existing invoice): ".$mysqli->error);
        $stmt->bind_param("i", $existing_invoice_id);
        $stmt->execute();
        $existing_inv = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        $stmt = $mysqli->prepare("SELECT * FROM invoice_items WHERE invoice_id = ? ORDER BY id ASC");
        if (!$stmt) die("Prepare failed (fetch existing items): ".$mysqli->error);
        $stmt->bind_param("i", $existing_invoice_id);
        $stmt->execute();
        $existing_items = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        // Populate invoice_data with existing data
        $invoice_data = [
            'invoice_no' => $existing_inv['invoice_no'],
            'invoice_date' => $existing_inv['invoice_date'],
            'company' => $company,
            'client' => $client,
            'items' => [],
            'total' => number_format((float)$existing_inv['total_amount'],2,'.',''),
            'notes' => $notes, // Re-use generated notes or fetch from invoice if added
            'payment_terms' => $payment_terms, // Re-use or fetch
            'saved_invoice_id' => $existing_invoice_id,
            'saved' => true,
            'invoice_period_display' => $display_month_name
        ];
        foreach($existing_items as $item) {
            $invoice_data['items'][] = [
                'description' => $item['description'],
                'sac_code' => $item['sac_code'],
                'qty' => (int)$item['qty'],
                'price_per_unit' => number_format((float)$item['price_per_unit'],2,'.',''),
                'amount' => number_format((float)$item['amount'],2,'.','')
            ];
        }

    } else {
        // --- Save New Invoice for the month
        $mysqli->begin_transaction();
        try {
            $status = 'Active';
            $created_by = $_SESSION['uid'] ?? null;
            $process_summary = "Monthly Invoice for " . $display_month_name; // Summary for the 'process' column

            $ins = $mysqli->prepare("INSERT INTO invoices (invoice_no, client_id, invoice_date, process, total_amount, status, created_by)
                                     VALUES (?,?,?,?,?,?,?)");
            if (!$ins) throw new Exception("Prepare failed (insert monthly invoice): " . $mysqli->error);
            // types: s i s s d s i  => "sissdsi"
            $ins->bind_param("sissdsi", $invoice_no, $client_id, $invoice_date, $process_summary, $total_calculated_amount, $status, $created_by);
            $ok = $ins->execute();
            if (!$ok) throw new Exception("Execute failed (insert monthly invoice): " . $ins->error);
            $new_invoice_id = $ins->insert_id;
            $ins->close();
            $invoice_id_for_print = $new_invoice_id;

            // Insert all jobs as individual items
            $inserted_items = [];
            $ins2 = $mysqli->prepare("INSERT INTO invoice_items (invoice_id, job_id, description, sac_code, qty, price_per_unit, amount)
                                      VALUES (?,?,?,?,?,?,?)");
            if (!$ins2) throw new Exception("Prepare failed (insert item): " . $mysqli->error);

            foreach($jobs_for_invoice as $job_item) {
                // types: i i s s i d d => "iissidd" (added job_id 'i')
                $job_item_job_id = $job_item['job_id'];
                $job_item_desc = $job_item['description'] . " (" . $job_item['process_name'] . ")"; // Add process name to description
                $job_item_sac_code = $job_item['sac_code'];
                $job_item_qty = $job_item['qty'];
                $job_item_price = $job_item['price_per_unit'];
                $job_item_amount = $job_item['amount'];

                $ins2->bind_param("iissidd", $new_invoice_id, $job_item_job_id, $job_item_desc, $job_item_sac_code, $job_item_qty, $job_item_price, $job_item_amount);
                $ok2 = $ins2->execute();
                if (!$ok2) throw new Exception("Execute failed (insert item for job #".$job_item_job_id."): " . $ins2->error);

                $inserted_items[] = [
                    'description' => $job_item_desc,
                    'sac_code' => $job_item_sac_code,
                    'qty' => $job_item_qty,
                    'price_per_unit' => number_format($job_item_price,2,'.',''),
                    'amount' => number_format($job_item_amount,2,'.','')
                ];
            }
            $ins2->close();

            $mysqli->commit();

            $invoice_data = [
                'invoice_no' => $invoice_no,
                'invoice_date' => $invoice_date,
                'company' => $company,
                'client' => $client,
                'items' => $inserted_items,
                'total' => number_format($total_calculated_amount,2,'.',''),
                'notes' => $notes,
                'payment_terms' => $payment_terms,
                'saved_invoice_id' => $new_invoice_id,
                'saved' => true,
                'invoice_period_display' => $display_month_name
            ];

        } catch (Exception $ex) {
            $mysqli->rollback();
            $errors[] = "Error saving invoice: " . $ex->getMessage();
        }
    }
}

// include header (same style as your other pages) - this will be hidden in print
require 'header.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Invoice - <?= h($invoice_data['invoice_no'] ?? 'Generating') ?></title>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
<style>
  /* Base Invoice Styles */
  body {
    font-family: Arial, sans-serif;
    color: #333;
    background-color: #f8f9fa;
  }
  .invoice-container {
    background-color: #fff;
    max-width: 900px;
    margin: 30px auto;
    padding: 30px;
    border: 1px solid #ddd;
    box-shadow: 0 0 10px rgba(0,0,0,0.1);
  }
  .invoice-header, .invoice-details, .invoice-items, .invoice-footer {
    margin-bottom: 20px;
  }
  .invoice-header h1 {
    font-size: 2.5em;
    color: #343a40;
    margin-bottom: 0;
  }
  .invoice-header .logo {
    max-width: 150px;
    float: right; /* Adjust as needed for your logo placement */
  }
  .invoice-header h2 {
    font-size: 1.2em;
    color: #6c757d;
  }
  .invoice-details strong {
    color: #555;
  }
  .invoice-items table {
    width: 100%;
    border-collapse: collapse;
    margin-top: 15px;
  }
  .invoice-items th, .invoice-items td {
    border: 1px solid #dee2e6;
    padding: 8px 12px;
    text-align: left;
  }
  .invoice-items th {
    background-color: #e9ecef;
    font-weight: bold;
    color: #495057;
  }
  .invoice-items .text-end {
    text-align: right;
  }
  .invoice-items .total-row td {
    font-size: 1.1em;
    font-weight: bold;
    background-color: #f2f2f2;
  }
  .invoice-bank-details p {
    margin-bottom: 5px;
  }
  .invoice-thank-you {
    text-align: center;
    margin-top: 40px;
    font-style: italic;
    color: #6c757d;
  }

  /* Specific Adjustments for Print */
  @media print {
    body {
      margin: 0;
      padding: 0;
      -webkit-print-color-adjust: exact; /* For background colors */
      print-color-adjust: exact;
      background-color: #fff; /* Ensure white background on print */
      font-size: 10pt; /* Smaller font for print */
    }
    .invoice-container {
      max-width: 100%;
      margin: 0;
      padding: 20px; /* Reduced padding for print */
      border: none;
      box-shadow: none;
    }
    /* Hide the main header included from header.php */
    #main-header-navbar { /* Assuming your header.php renders a navbar with this ID */
      display: none !important;
    }
    .no-print {
      display: none !important;
    }
    .table-bordered th, .table-bordered td {
        border: 1px solid #dee2e6 !important; /* Ensure borders print */
    }
    /* Ensure no page breaks inside critical sections */
    .invoice-header, .invoice-details, .invoice-items, .invoice-footer {
        page-break-inside: avoid;
    }
    /* Prevent orphan/widow lines */
    p {
        orphans: 3;
        widows: 3;
    }
    h1, h2, h3, h4, h5, h6 {
        page-break-after: avoid;
    }
  }
</style>
</head>
<body>
<!-- The included header.php will be here and potentially hidden by print CSS -->
<div class="no-print">
    <!-- Header content from header.php will be here -->
</div>

<div class="container invoice-container">

<?php if (!empty($errors)): ?>
  <div class="alert alert-danger no-print">
    <strong>Errors/Warnings:</strong>
    <ul class="mb-0">
      <?php foreach($errors as $e): ?><li><?= h($e) ?></li><?php endforeach; ?>
    </ul>
  </div>
<?php endif; ?>

<?php if ($invoice_data): ?>
  <div class="invoice-header row">
    <div class="col-6">
      <h1>INVOICE</h1>
      <p class="mb-1"><strong>Invoice No:</strong> <?= h($invoice_data['invoice_no']) ?></p>
      <p class="mb-1"><strong>Invoice Date:</strong> <?= h(formatDate($invoice_data['invoice_date'])) ?></p>
      <p class="mb-1"><strong>Invoice Period:</strong> <?= h($invoice_data['invoice_period_display']) ?></p>
    </div>
    <div class="col-6 text-end">
      <?php if (!empty($invoice_data['company']['logo_path'])): ?>
        <img src="<?= h($invoice_data['company']['logo_path']) ?>" alt="Company Logo" class="logo mb-2">
      <?php endif; ?>
      <h2><?= h($invoice_data['company']['company_name'] ?? 'TAPASI Solutions LLP') ?></h2>
      <p class="mb-0"><?= nl2br(h($invoice_data['company']['company_address'] ?? '')) ?></p>
      <p class="mb-0">PAN: <?= h($invoice_data['company']['pan_number'] ?? '') ?></p>
      <p class="mb-0">GST: <?= h($invoice_data['company']['gst_number'] ?? '') ?></p>
    </div>
  </div>

  <hr class="my-4">

  <div class="invoice-details row">
    <div class="col-6">
      <h5>BILL TO:</h5>
      <p class="mb-0"><strong><?= h($invoice_data['client']['client_name'] ?? 'N/A') ?></strong></p>
      <p class="mb-0"><?= nl2br(h($invoice_data['client']['address'] ?? '')) ?></p>
      <p class="mb-0">GST: <?= h($invoice_data['client']['gst_no'] ?? '') ?></p>
      <p class="mb-0">Email: <?= h($invoice_data['client']['email'] ?? '') ?></p>
    </div>
    <div class="col-6 text-end">
      <!-- You can add a PO number or other client-specific details here if available -->
    </div>
  </div>

  <hr class="my-4">

  <div class="invoice-items">
    <h5>Invoice Items:</h5>
    <table class="table table-sm">
      <thead>
        <tr>
          <th style="width: 5%;">#</th>
          <th>Description</th>
          <th style="width: 15%;" class="text-end">SAC Code</th>
          <th style="width: 10%;" class="text-end">Qty (Pages)</th>
          <th style="width: 15%;" class="text-end">Price/Unit (USD)</th>
          <th style="width: 15%;" class="text-end">Amount (USD)</th>
        </tr>
      </thead>
      <tbody>
        <?php $item_count = 1; ?>
        <?php foreach($invoice_data['items'] as $it): ?>
          <tr>
            <td><?= $item_count++ ?></td>
            <td><?= h($it['description']) ?></td>
            <td class="text-end"><?= h($it['sac_code']) ?></td>
            <td class="text-end"><?= (int)$it['qty'] ?></td>
            <td class="text-end"><?= formatCurrency($it['price_per_unit']) ?></td>
            <td class="text-end"><?= formatCurrency($it['amount']) ?></td>
          </tr>
        <?php endforeach; ?>
        <tr class="total-row">
          <td colspan="5" class="text-end"><strong>Total Amount Due</strong></td>
          <td class="text-end"><strong><?= formatCurrency($invoice_data['total']) ?></strong></td>
        </tr>
      </tbody>
    </table>
  </div>

  <hr class="my-4">

  <div class="invoice-footer row">
    <div class="col-6">
      <h5>Payment Instructions:</h5>
      <p class="mb-1"><strong>Payment Terms:</strong> <?= h($invoice_data['payment_terms'] ?? 'Within 15 days') ?></p>
      <div class="invoice-bank-details">
        <p class="mb-0"><strong>Bank Account Name:</strong> <?= h($invoice_data['company']['bank_account_name'] ?? '') ?></p>
        <p class="mb-0"><strong>Bank Name:</strong> <?= h($invoice_data['company']['bank_name'] ?? '') ?></p>
        <p class="mb-0"><strong>Account Number:</strong> <?= h($invoice_data['company']['bank_account_number'] ?? '') ?></p>
        <p class="mb-0"><strong>IFSC Code:</strong> <?= h($invoice_data['company']['bank_ifsc_code'] ?? '') ?></p>
        <p class="mb-0"><strong>Swift Code:</strong> <?= h($invoice_data['company']['bank_swift_code'] ?? '') ?></p>
      </div>
    </div>
    <div class="col-6 text-end">
      <p class="mb-1"><strong>Notes:</strong><br><?= nl2br(h($invoice_data['notes'] ?? '')) ?></p>
    </div>
  </div>

  <div class="invoice-thank-you">
    <p>Thank you for your business!</p>
  </div>

  <div class="no-print text-center mt-4">
    <button onclick="window.print()" class="btn btn-lg btn-primary">Print Invoice</button>
  </div>

<?php else: ?>
  <div class="alert alert-warning">
    Could not generate invoice. Please ensure valid client ID and invoice month are provided and relevant data exists.
  </div>
<?php endif; ?>

</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>