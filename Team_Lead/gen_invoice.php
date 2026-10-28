<?php
// --- Configuration & Setup ---
// NOTE: db.php and header.php are still required to get client/job/company data
require 'db.php';
if (session_status() === PHP_SESSION_NONE) { session_start(); }

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function formatDate($d){ return (!empty($d) && $d!='0000-00-00')?date('d-M-Y', strtotime($d)):'—'; }

// --- Fetch jobs that have at least one completed entry in ce/le/pag/qc
// (This query is needed to populate the job selection dropdown)
$jobs_sql = "
SELECT DISTINCT j.job_id, j.job_date, c.client_name, j.journal_issue, j.article, jm.job_name AS process_name
FROM jobs j
LEFT JOIN client_master c ON c.client_id = j.client_id
LEFT JOIN job_master jm ON jm.job_id = j.process_id
WHERE
    EXISTS (SELECT 1 FROM ce WHERE ce.job_id = j.job_id AND ce.status = 'Completed')
    OR EXISTS (SELECT 1 FROM le WHERE le.job_id = j.job_id AND le.status = 'Completed')
    OR EXISTS (SELECT 1 FROM pag WHERE pag.job_id = j.job_id AND pag.status = 'Completed')
    OR EXISTS (SELECT 1 FROM qc WHERE qc.job_id = j.job_id AND qc.status = 'Completed')
ORDER BY j.job_date DESC
";
$jobs_res = $mysqli->query($jobs_sql);
if ($jobs_res === false) {
    die("Jobs query failed: " . $mysqli->error);
}

// --- Initial variable setup (used to pre-fill the form) ---
$preview_data = null;
$errors = [];
$job_id = 0;
// Default values for form inputs
$invoice_no = 'INV'.date('Ym').'001';
$invoice_date = date('Y-m-d');
$sac_code = '998319';
$qty = 1;
$price_per_unit = 0.00;
$notes = 'Payment to be made via bank transfer to the account details provided.';
$payment_terms = 'Within 15 days';

// --- POST handling: preview only ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // The 'action' variable is now only used to trigger preview logic
    $job_id = isset($_POST['job_id']) ? (int)$_POST['job_id'] : 0;
    
    // fetch job details (required for all actions)
    if ($job_id > 0) {
        $stmt = $mysqli->prepare("SELECT j.job_id, j.job_date, j.client_id, j.journal_issue, j.article, jm.job_name AS process_name, jm.rate
                                 FROM jobs j
                                 LEFT JOIN job_master jm ON jm.job_id = j.process_id
                                 WHERE j.job_id = ?");
        if (!$stmt) die("Prepare failed (job): ".$mysqli->error);
        $stmt->bind_param("i", $job_id);
        $stmt->execute();
        $job = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$job) $errors[] = "Job not found.";
    } else {
        $errors[] = "Please select a valid Job ID.";
    }

    // fetch client & company (if job is found)
    if (!$errors) {
        $stmt = $mysqli->prepare("SELECT * FROM client_master WHERE client_id = ?");
        if (!$stmt) die("Prepare failed (client): ".$mysqli->error);
        $stmt->bind_param("i", $job['client_id']);
        $stmt->execute();
        $client = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        $company = $mysqli->query("SELECT * FROM company_master ORDER BY id DESC LIMIT 1");
        $company = $company ? $company->fetch_assoc() : [];
    }

    // try to fetch pages from pag table (most recent) - only used as default for preview
    $pages_from_db = 0;
    if (!$errors) {
        $stmt = $mysqli->prepare("SELECT pages FROM pag WHERE job_id = ? ORDER BY id DESC LIMIT 1");
        if ($stmt) {
            $stmt->bind_param("i", $job_id);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if ($row && isset($row['pages'])) $pages_from_db = (int)$row['pages'];
        }
    }
    
    // Get form values (read from POST to update the form fields and calculation)
    $invoice_no = trim($_POST['invoice_no'] ?? $invoice_no);
    $invoice_date = trim($_POST['invoice_date'] ?? $invoice_date);
    $sac_code = trim($_POST['sac_code'] ?? $sac_code);
    
    // QTY: Use form value if provided, else use DB default
    $qty = (int)($_POST['pages'] ?? $pages_from_db);
    $qty = max(1, $qty); // ensure quantity is at least 1
    
    // PRICE: Use form value if provided, else use job master rate
    $price_per_unit = is_numeric($_POST['price_per_unit'] ?? '') ? (float)$_POST['price_per_unit'] : (float)($job['rate'] ?? 0.00);
    $price_per_unit = max(0.01, $price_per_unit); // ensure price is sensible
    
    $notes = trim($_POST['notes'] ?? $notes);
    $payment_terms = trim($_POST['payment_terms'] ?? $payment_terms);

    // computation
    $amount = round($qty * $price_per_unit, 2);
    $total_amount = $amount;
    
    // Build preview array (if no fatal errors)
    if (!$errors && $job_id > 0) {
        $preview_data = [
            'invoice_no' => $invoice_no ?: 'INV'.time(), 
            'invoice_date' => $invoice_date,
            'company' => $company,
            'client' => $client,
            'job' => $job,
            'items' => [
                [
                    'description' => $job['article'] ?: $job['journal_issue'] ?: ('Job ID ' . $job['job_id']),
                    'sac_code' => $sac_code,
                    'qty' => $qty,
                    'price_per_unit' => number_format($price_per_unit, 2, '.', ''),
                    'amount' => number_format($amount, 2, '.', '')
                ]
            ],
            'total' => number_format($total_amount, 2, '.', ''),
            'notes' => $notes,
            'payment_terms' => $payment_terms
        ];
    }
    // --- NO SAVE LOGIC HERE --- 
}

// Re-fetch jobs resource if it was consumed by the loop (to re-populate the select box)
if (isset($_POST['job_id'])) {
    $jobs_res = $mysqli->query($jobs_sql);
}

// include header
require 'header.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Create Invoice Preview</title>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
<style>
    /* Custom styles for the professional invoice look */
    .invoice-container {
        border: 1px solid #dee2e6;
        background-color: #fff;
    }
    .invoice-container .card-header {
        background-color: #0d6efd !important; /* Primary blue for header */
    }
    .invoice-container .text-primary {
        color: #0d6efd !important;
    }
    .invoice-container .table-dark th {
        background-color: #343a40;
        color: white;
    }
    .invoice-container .table-primary {
        background-color: #cfe2ff !important;
    }
    .position-absolute {
        z-index: 10;
    }
</style>
</head>
<body>
<div class="container mt-3">

<?php if (!empty($errors)): ?>
    <div class="alert alert-danger">
        <strong>Errors:</strong>
        <ul class="mb-0">
            <?php foreach($errors as $e): ?><li><?= h($e) ?></li><?php endforeach; ?>
        </ul>
    </div>
<?php endif; ?>

<div class="d-flex justify-content-between align-items-center mb-3">
    <h5>Invoice Preview Tool (No DB Insert)</h5>
    <a href="job_master.php" class="btn btn-sm btn-secondary"><i class="fas fa-arrow-left"></i> Back to Jobs</a>
</div>

<div class="card mb-4 shadow-sm">
  <div class="card-body">
    <form method="post" class="row g-3">
      
      <div class="col-md-12 mb-3">
        <label class="form-label small">Select Completed Job</label>
        <select name="job_id" class="form-select" required onchange="this.form.submit()"
                id="job_select_field"
        >
          <option value="">-- select job --</option>
          <?php while($j = $jobs_res->fetch_assoc()): ?>
            <option value="<?= (int)$j['job_id'] ?>" 
              <?= ((int)$job_id === (int)$j['job_id']) ? 'selected' : '' ?>>
              #<?= (int)$j['job_id'] ?> — <?= h($j['client_name'] ?? 'Client') ?> / <?= h($j['journal_issue'] ?: $j['article']) ?> (<?= h($j['process_name']) ?>)
            </option>
          <?php endwhile; ?>
        </select>
        <small class="form-text text-muted">Selecting a job will refresh the form to load defaults.</small>
      </div>

      <div class="col-md-4">
        <label class="form-label small">Invoice No.</label>
        <input type="text" name="invoice_no" class="form-control form-control-sm" 
               value="<?= h($invoice_no) ?>" placeholder="e.g., INV<?= date('Ym').'001' ?>" required>
      </div>
      <div class="col-md-4">
        <label class="form-label small">Invoice Date</label>
        <input type="date" name="invoice_date" class="form-control form-control-sm" 
               value="<?= h($invoice_date) ?>" required>
      </div>
      <div class="col-md-4">
        <label class="form-label small">SAC Code</label>
        <input type="text" name="sac_code" class="form-control form-control-sm" 
               value="<?= h($sac_code) ?>" required>
      </div>
      
      <div class="col-md-4">
        <label class="form-label small">Quantity (Pages)</label>
        <input type="number" name="pages" class="form-control form-control-sm" 
               value="<?= h($qty) ?>" min="1" step="1" required>
      </div>
      <div class="col-md-4">
        <label class="form-label small">Price/Unit (USD)</label>
        <input type="number" name="price_per_unit" class="form-control form-control-sm" 
               value="<?= h(number_format($price_per_unit, 2, '.', '')) ?>" min="0.01" step="0.01" required>
        <small class="form-text text-muted">Default is job master rate.</small>
      </div>
      <div class="col-md-4">
        <label class="form-label small">Payment Terms</label>
        <input type="text" name="payment_terms" class="form-control form-control-sm" 
               value="<?= h($payment_terms) ?>">
      </div>
      
      <div class="col-md-8">
        <label class="form-label small">Notes</label>
        <textarea name="notes" class="form-control form-control-sm" rows="2"><?= h($notes) ?></textarea>
      </div>
      
      <div class="col-md-4 d-flex align-items-end justify-content-end gap-2">
        <button type="submit" name="action" value="preview" class="btn btn-sm btn-primary flex-grow-1" 
                <?= ($job_id === 0) ? 'disabled' : '' ?>>
          <i class="fas fa-eye"></i> Preview Invoice
        </button>
        </div>
    </form>
  </div>
</div>

<?php if ($preview_data): ?>
  <div class="card shadow-lg invoice-container mb-5">
    <div class="card-header bg-primary text-white text-center py-3 position-relative">
      <h4 class="mb-0">TAX INVOICE (PREVIEW ONLY)</h4>
      </div>
    
    <div class="card-body p-5">
      <div class="row mb-5">
        <div class="col-sm-6">
          <h5 class="mb-1 text-primary"><?= h($preview_data['company']['company_name'] ?? 'COMPANY NAME') ?></h5>
          <p class="small mb-0"><?= nl2br(h($preview_data['company']['company_address'] ?? '')) ?></p>
          <p class="small mb-0">GSTIN: <?= h($preview_data['company']['pan_number'] ?? '') ?></p>
          <p class="small mb-0">PAN: <?= h($preview_data['company']['pan_number'] ?? '') ?></p>
        </div>
        
        <div class="col-sm-6 text-end">
          <p class="mb-1"><strong>Invoice No:</strong> <span class="text-primary fs-5"><?= h($preview_data['invoice_no']) ?></span></p>
          <p class="mb-1"><strong>Invoice Date:</strong> <?= h(formatDate($preview_data['invoice_date'])) ?></p>
          <p class="mb-1"><strong>Job ID:</strong> <?= (int)$preview_data['job']['job_id'] ?></p>
        </div>
      </div>
      
      <hr class="mb-5">

      <div class="row mb-5">
        <div class="col-12">
          <h6 class="mb-2 text-primary">BILL TO:</h6>
          <p class="mb-1 fw-bold"><?= h($preview_data['client']['client_name'] ?? 'N/A') ?></p>
          <p class="small mb-0"><?= nl2br(h($preview_data['client']['address'] ?? '')) ?></p>
          <p class="small mb-0">GST/Tax No: <?= h($preview_data['client']['gst_no'] ?? 'N/A') ?></p>
        </div>
      </div>

      <div class="table-responsive mb-5">
        <table class="table table-bordered table-sm">
          <thead class="table-dark">
            <tr>
              <th style="width: 5%;">#</th>
              <th style="width: 45%;">Description</th>
              <th style="width: 10%;">SAC Code</th>
              <th class="text-end" style="width: 10%;">Qty</th>
              <th class="text-end" style="width: 15%;">Price/Unit (USD)</th>
              <th class="text-end" style="width: 15%;">Amount (USD)</th>
            </tr>
          </thead>
          <tbody>
            <?php $item = $preview_data['items'][0]; ?>
            <tr>
              <td>1</td>
              <td>
                <p class="mb-0"><strong><?= h($item['description']) ?></strong></p>
                <p class="small text-muted mb-0">Process: <?= h($preview_data['job']['process_name'] ?? 'N/A') ?></p>
              </td>
              <td><?= h($item['sac_code']) ?></td>
              <td class="text-end"><?= (int)$item['qty'] ?></td>
              <td class="text-end">$<?= number_format((float)$item['price_per_unit'], 2) ?></td>
              <td class="text-end">$<?= number_format((float)$item['amount'], 2) ?></td>
            </tr>
            <tr>
              <td colspan="5" class="text-end border-0 pt-3"><strong>SUBTOTAL</strong></td>
              <td class="text-end border-0 pt-3"><strong>$<?= number_format((float)$preview_data['total'], 2) ?></strong></td>
            </tr>
            <tr class="table-primary fw-bold">
              <td colspan="5" class="text-end">TOTAL DUE</td>
              <td class="text-end">$<?= number_format((float)$preview_data['total'], 2) ?></td>
            </tr>
          </tbody>
        </table>
      </div>

      <div class="row">
        <div class="col-md-6">
          <h6 class="text-primary">Notes:</h6>
          <p class="small"><?= nl2br(h($preview_data['notes'] ?? '')) ?></p>
          <p class="small"><strong>Payment Terms:</strong> <?= h($preview_data['payment_terms']) ?></p>
        </div>
        <div class="col-md-6 text-end">
          <h6 class="text-primary">Our Banker's Details:</h6>
          <p class="small mb-0">A/C Name: <?= h($preview_data['company']['bank_account_name'] ?? '') ?></p>
          <p class="small mb-0">Bank: <?= h($preview_data['company']['bank_name'] ?? '') ?></p>
          <p class="small mb-0">A/C No: <?= h($preview_data['company']['bank_account_number'] ?? '') ?></p>
          <p class="small mb-0">IFSC: <?= h($preview_data['company']['bank_ifsc_code'] ?? '') ?></p>
          <p class="small">SWIFT: <?= h($preview_data['company']['bank_swift_code'] ?? '') ?></p>
        </div>
      </div>
      
      <p class="text-center mt-5 border-top pt-3 small text-muted">Thank you for your business!</p>

    </div>
  </div>
<?php endif; ?>

</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>