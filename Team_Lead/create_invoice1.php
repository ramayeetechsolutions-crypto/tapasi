<?php
require 'db.php';
if (session_status() === PHP_SESSION_NONE) { session_start(); }

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function formatDate($d){ return (!empty($d) && $d!='0000-00-00')?date('d-M-Y', strtotime($d)):'—'; }

// --- Fetch jobs that have at least one completed entry in ce/le/pag/qc
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

// --- POST handling: preview / save
$preview_data = null;
$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $job_id = isset($_POST['job_id']) ? (int)$_POST['job_id'] : 0;
    if ($job_id <= 0) $errors[] = "Please select a valid Job ID.";

    // fetch job
    if (!$errors) {
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
    }

    // fetch client & company
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

    // try to fetch pages from pag table (most recent)
    $pages_from_db = 0;
    if (!$errors) {
        $stmt = $mysqli->prepare("SELECT pages FROM pag WHERE job_id = ? ORDER BY id DESC LIMIT 1");
        if ($stmt) {
            $stmt->bind_param("i", $job_id);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if ($row && isset($row['pages'])) $pages_from_db = (int)$row['pages'];
        } else {
            // not fatal — just continue
            $pages_from_db = 0;
        }
    }

    // get form values (allow override)
    $invoice_no = trim($_POST['invoice_no'] ?? '');
    $invoice_date = trim($_POST['invoice_date'] ?? date('Y-m-d'));
    $sac_code = trim($_POST['sac_code'] ?? '998319');
    $qty = (int)($_POST['pages'] ?? $pages_from_db);
    $price_per_unit = is_numeric($_POST['price_per_unit'] ?? '') ? (float)$_POST['price_per_unit'] : (float)($job['rate'] ?? 0.00);
    $notes = trim($_POST['notes'] ?? '');
    $payment_terms = trim($_POST['payment_terms'] ?? 'Within 15 days');

    // compute amounts
    $amount = round($qty * $price_per_unit, 2);
    $total_amount = $amount;

    // build preview array
    $preview_data = [
        'invoice_no' => $invoice_no ?: 'INV'.time(),
        'invoice_date' => $invoice_date,
        'company' => $company,
        'client' => $client,
        'job' => $job,
        'items' => [
            [
                'description' => $job['article'] ?: $job['journal_issue'] ?: $job['job_id'],
                'sac_code' => $sac_code,
                'qty' => $qty,
                'price_per_unit' => number_format($price_per_unit,2,'.',''),
                'amount' => number_format($amount,2,'.','')
            ]
        ],
        'total' => number_format($total_amount,2,'.',''),
        'notes' => $notes,
        'payment_terms' => $payment_terms
    ];

    // If saving -> insert into invoices & invoice_items
    if ($action === 'save' && empty($errors)) {
        // start transaction
        $mysqli->begin_transaction();

        try {
            $status = 'Active';
            $created_by = $_SESSION['uid'] ?? null; // allow null if not available

            $ins = $mysqli->prepare("INSERT INTO invoices (invoice_no, client_id, invoice_date, process, total_amount, status, created_by)
                                     VALUES (?,?,?,?,?,?,?)");
            if (!$ins) throw new Exception("Prepare failed (insert invoice): " . $mysqli->error);
            // types: s i s s d s i  => "sissdsi"
            $proc_name = $job['process_name'] ?? '';
            $ins->bind_param("sissdsi", $preview_data['invoice_no'], $job['client_id'], $preview_data['invoice_date'], $proc_name, $total_amount, $status, $created_by);
            $ok = $ins->execute();
            if (!$ok) throw new Exception("Execute failed (insert invoice): " . $ins->error);
            $invoice_id = $ins->insert_id;
            $ins->close();

            // insert item(s) — single item for now
            $it = $preview_data['items'][0];
            $ins2 = $mysqli->prepare("INSERT INTO invoice_items (invoice_id, description, sac_code, qty, price_per_unit, amount)
                                      VALUES (?,?,?,?,?,?)");
            if (!$ins2) throw new Exception("Prepare failed (insert item): " . $mysqli->error);
            $ins2->bind_param("issidd", $invoice_id, $it['description'], $it['sac_code'], $it['qty'], $price_per_unit, $amount);
            $ok2 = $ins2->execute();
            if (!$ok2) throw new Exception("Execute failed (insert item): " . $ins2->error);
            $ins2->close();

            $mysqli->commit();

            // put saved invoice id into preview_data so we can show "Saved" badge
            $preview_data['saved_invoice_id'] = $invoice_id;
            $preview_data['saved'] = true;
        } catch (Exception $ex) {
            $mysqli->rollback();
            $errors[] = $ex->getMessage();
        }
    }
}

// include header (same style as your other pages)
require 'header.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Create Invoice</title>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
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
  <h5>Create Invoice</h5>
  <a href="job_master.php" class="btn btn-sm btn-secondary">Back</a>
</div>

<div class="card mb-4">
  <div class="card-body">
    <form method="post" class="row g-3">
      <div class="col-md-6">
        <label class="form-label small">Select Completed Job</label>
        <select name="job_id" class="form-select form-select-sm" required>
          <option value="">-- select job --</option>
          <?php while($j = $jobs_res->fetch_assoc()): ?>
            <option value="<?= (int)$j['job_id'] ?>">
              #<?= (int)$j['job_id'] ?> — <?= h($j['client_name'] ?? 'Client') ?> / <?= h($j['journal_issue'] ?: $j['article']) ?> (<?= h($j['process_name']) ?>)
            </option>
          <?php endwhile; ?>
        </select>
      </div>

      <div class="col-md-6 d-flex align-items-end gap-2">
        <button type="submit" name="action" value="preview" class="btn btn-sm btn-primary">Preview</button>
        <button type="submit" name="action" value="save" class="btn btn-sm" style="background-color:brown;color:white;">Save & Insert</button>
      </div>
    </form>
  </div>
</div>

<?php if ($preview_data): ?>
  <div class="card">
    <div class="card-body">
      <div class="row mb-2">
        <div class="col-md-6">
          <p><strong>Company:</strong> <?= h($preview_data['company']['company_name'] ?? 'TAPASI Solutions LLP') ?></p>
          <p><strong>Address:</strong><br><?= nl2br(h($preview_data['company']['company_address'] ?? '')) ?></p>
        </div>
        <div class="col-md-6 text-end">
          <p><strong>Date</strong><br><?= h($preview_data['invoice_date']) ?></p>
          <p><strong>PDF</strong><br><?= h($preview_data['invoice_no']) ?></p>
          <?php if (!empty($preview_data['saved'])): ?>
            <span class="badge bg-success">Saved (Invoice ID: <?= (int)$preview_data['saved_invoice_id'] ?>)</span>
          <?php endif; ?>
        </div>
      </div>

      <div class="row mb-2">
        <div class="col-md-6">
          <p><strong>Bill To</strong><br><?= h($preview_data['client']['client_name'] ?? '') ?><br><?= nl2br(h($preview_data['client']['address'] ?? '')) ?><br>GST: <?= h($preview_data['client']['gst_no'] ?? '') ?></p>
        </div>
        <div class="col-md-6 text-end">
          <p><strong>From</strong><br><?= h($preview_data['company']['company_name'] ?? '') ?><br>PAN: <?= h($preview_data['company']['pan_number'] ?? '') ?><br>GST: <?= h($preview_data['company']['pan_number'] ?? '') ?></p>
        </div>
      </div>

      <hr>
      <p>We are pleased to invoice you the following for your kind consideration.</p>

      <div class="table-responsive">
        <table class="table table-bordered table-sm">
          <thead class="table-light">
            <tr>
              <th>#</th>
              <th>Description</th>
              <th>SAC Code</th>
              <th>Qty (Pages)</th>
              <th>Price/Pdf page (USD)</th>
              <th>Amount (USD)</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach($preview_data['items'] as $i => $it): ?>
              <tr>
                <td><?= $i+1 ?></td>
                <td><?= h($it['description']) ?></td>
                <td><?= h($it['sac_code']) ?></td>
                <td class="text-end"><?= (int)$it['qty'] ?></td>
                <td class="text-end"><?= number_format((float)$it['price_per_unit'],2) ?></td>
                <td class="text-end"><?= number_format((float)$it['amount'],2) ?></td>
              </tr>
            <?php endforeach; ?>
            <tr>
              <td colspan="5" class="text-end"><strong>Total</strong></td>
              <td class="text-end"><strong><?= number_format((float)$preview_data['total'],2) ?></strong></td>
            </tr>
          </tbody>
        </table>
      </div>

      <div class="row">
        <div class="col-md-6">
          <p><strong>Payment:</strong> <?= h($preview_data['payment_terms'] ?? 'Within 15 days') ?></p>
          <p><?= nl2br(h($preview_data['notes'] ?? '')) ?></p>
        </div>
        <div class="col-md-6 text-end">
          <h6>Our Banker's</h6>
          <p>
            Account Name: <?= h($preview_data['company']['bank_account_name'] ?? '') ?><br>
            Bank Name: <?= h($preview_data['company']['bank_name'] ?? '') ?><br>
            Account Number: <?= h($preview_data['company']['bank_account_number'] ?? '') ?><br>
            IFSC Code: <?= h($preview_data['company']['bank_ifsc_code'] ?? '') ?><br>
            Swift Code: <?= h($preview_data['company']['bank_swift_code'] ?? '') ?>
          </p>
        </div>
      </div>

      <p class="text-center mt-3">Thank you</p>
    </div>
  </div>
<?php endif; ?>

</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
