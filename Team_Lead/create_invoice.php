<?php
require 'db.php';
if (session_status() === PHP_SESSION_NONE) { session_start(); }

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function redirect_with($params = []) {
    $base = 'create_invoice.php';
    if (!empty($params)) $base .= '?' . http_build_query($params);
    header("Location: $base");
    exit;
}

// ---------- Handle Insert ----------
// fetch client list
$client_id = isset($_GET['client_id']) ? (int)$_GET['client_id'] : 0;
$invoice_month = $_GET['invoice_month'] ?? date('Y-m'); // fallback current month

//invoice number
$month = (int)date('m');
    $year = (int)date('y'); // 2 digit
    $invoice_seq =($month >= 4) ? $year+1 : $year; 
    
    $fy=$invoice_seq;
    // Fetch last invoice for this FY
    $sql = "SELECT invoice_no FROM client_invoice 
            WHERE invoice_no LIKE 'TPS$fy/%' 
            ORDER BY invoice_id DESC LIMIT 1";
    $res = $mysqli->query($sql);

    $nextSeq = 1;
    if ($res && $row = $res->fetch_assoc()) {
        // invoice_no looks like TPS26/193
        $parts = explode('/', $row['invoice_no']);
        if (isset($parts[1])) {
            $nextSeq = (int)$parts[1] + 1;
        }
    }
$seqStr = str_pad($nextSeq, 5, '0', STR_PAD_LEFT);
    $invoice_no= "TPS{$fy}/" . $seqStr;
    
    
    
// Optional: fetch client name for display
$client_name = '';
$clients = [];
$result = $mysqli->query("SELECT client_id, client_name FROM client_master ORDER BY client_name ASC");
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $clients[] = $row;
    }
}


if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    
    $client_id      = (int)($_POST['client_id'] ?? 0);
    $invoice_no     = trim($_POST['invoice_no'] ?? '');
    $invoice_date   = $_POST['invoice_date'] ?? date('Y-m-d');
    $ce             = (int)($_POST['ce'] ?? 0);
    $pag            = (int)($_POST['pag'] ?? 0);
    $xml            = (int)($_POST['xml'] ?? 0);
    $math           = (int)($_POST['math'] ?? 0);
    $ref_img        = (int)($_POST['ref_img'] ?? 0);
    $price_per_page = (float)($_POST['price_per_page'] ?? 0);
    $total          = (float)($_POST['total'] ?? 0);
    $tax            = (float)($_POST['tax'] ?? 0);
    $net_total      = (float)($_POST['net_total'] ?? 0);
    $tat            = trim($_POST['tat'] ?? '');

    if ($client_id === 0 || $invoice_no === '') {
        redirect_with(['msg'=>'Client and Invoice No are required.','type'=>'danger']);
    }

    $stmt = $mysqli->prepare("INSERT INTO client_invoice 
        (client_id, invoice_no, invoice_date, ce, pag, xml, math, ref_img, price_per_page, total, tax, net_total, tat)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)");
    $stmt->bind_param("issiiiiidddds",
        $client_id, $invoice_no, $invoice_date, $ce, $pag, $xml, $math, $ref_img,
        $price_per_page, $total, $tax, $net_total, $tat
    );
   if ($stmt->execute()) {
    $new_invoice_id = $stmt->insert_id; // last inserted id
       header("Location: generate_invoice.php?invoice_id={$new_invoice_id}&invoice_month={$invoice_month}");

    exit;
} else {
    redirect_with(['msg'=>'Error: '.$stmt->error,'type'=>'danger']);
}

}
require 'header.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>TAPASI</title>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
</head>
<body>
<div class="container mt-3">

<?php if (!empty($_GET['msg'])): ?>
<div class="alert alert-<?= h($_GET['type'] ?? 'info') ?> alert-dismissible fade show" role="alert">
  <?= h($_GET['msg']) ?>
  <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>

<div class="card mx-auto" style="max-width:900px;">
  <div class="card-body">
    <h5 class="mb-3">Client Invoice</h5>
    <form method="post" autocomplete="off">
      <div class="row g-3">
      <div class="col-md-6">
  <label class="form-label small">Client <span class="text-danger">*</span></label>
  <div class="input-group input-group-sm">
    <span class="input-group-text"><i class="fa fa-user"></i></span>
    <select name="client_id" class="form-select" required>
        <option value="">-- Select Client --</option>
        <?php foreach ($clients as $c): ?>
          <option value="<?= h($c['client_id']) ?>" 
    <?= ($client_id == $c['client_id']) ? 'selected' : '' ?>>
    <?= h($c['client_name']) ?>
</option>
        <?php endforeach; ?>
    </select>
  </div>

        </div>
        <div class="col-md-6">
          <label class="form-label small">Invoice No <span class="text-danger">*</span></label>
          <input type="text" name="invoice_no" value="<?php echo $invoice_no;?>" class="form-control form-control-sm" required readonly>
        </div>
        <div class="col-md-6">
          <label class="form-label small">Date</label>
          <input type="date" name="invoice_date" class="form-control form-control-sm" value="<?= date('Y-m-d') ?>">
        </div>
<div class="col-md-2">
  <label class="form-label small">CE</label>
  <input type="number" id="ce" name="ce" class="form-control form-control-sm" value="0">
</div>
<div class="col-md-2">
  <label class="form-label small">PAG</label>
  <input type="number" id="pag" name="pag" class="form-control form-control-sm" value="0">
</div>
<div class="col-md-2">
  <label class="form-label small">XML</label>
  <input type="number" id="xml" name="xml" class="form-control form-control-sm" value="0">
</div>
<div class="col-md-2">
  <label class="form-label small">MATH</label>
  <input type="number" id="math" name="math" class="form-control form-control-sm" value="0">
</div>
<div class="col-md-2">
  <label class="form-label small">REF/IMG</label>
  <input type="number" id="ref_img" name="ref_img" class="form-control form-control-sm" value="0">
</div>

<div class="col-md-4">
  <label class="form-label small">Price / Page</label>
  <input type="number" step="0.01" id="price_per_page" name="price_per_page" class="form-control form-control-sm" required>
</div>
<div class="col-md-4">
  <label class="form-label small">Total</label>
  <input type="number" step="0.01" id="total" name="total" class="form-control form-control-sm" readonly>
</div>
<div class="col-md-2">
  <label class="form-label small">Tax (%)</label>
  <input type="number" step="0.01" id="tax" name="tax" class="form-control form-control-sm" value="18">
</div>
<div class="col-md-2">
  <label class="form-label small">Net Total</label>
  <input type="number" step="0.01" id="net_total" name="net_total" class="form-control form-control-sm" readonly>
</div>

        <div class="col-md-6">
          <label class="form-label small">TAT</label>
          <input type="text" name="tat" class="form-control form-control-sm">
        </div>
      </div>

      <div class="d-flex justify-content-end gap-2 mt-4">
        <button type="submit" class="btn btn-sm btn-primary">Save</button>
        <a href="client_master.php" class="btn btn-sm btn-outline-secondary">Back</a>
      </div>
    </form>
  </div>
</div>

</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
function calculateTotals() {
    let ce = parseInt(document.getElementById("ce").value) || 0;
    let pag = parseInt(document.getElementById("pag").value) || 0;
    let xml = parseInt(document.getElementById("xml").value) || 0;
    let math = parseInt(document.getElementById("math").value) || 0;
    let refImg = parseInt(document.getElementById("ref_img").value) || 0;
    let price = parseFloat(document.getElementById("price_per_page").value) || 0;
    let taxPercent = parseFloat(document.getElementById("tax").value) || 0;

    // XML + MATH should be combined
    let totalPages = ce + pag + (xml + math) + refImg;

    // Total amount
    let total = totalPages * price;
    document.getElementById("total").value = total.toFixed(2);

    // Tax amount
    let taxAmount = (total * taxPercent) / 100;

    // Net total = total + tax
    let netTotal = total + taxAmount;
    document.getElementById("net_total").value = netTotal.toFixed(2);
}

// Attach listeners
["ce","pag","xml","math","ref_img","price_per_page","tax"].forEach(id => {
    document.getElementById(id).addEventListener("input", calculateTotals);
});

// Initial calc on page load
calculateTotals();
</script>

</body>
</html>
