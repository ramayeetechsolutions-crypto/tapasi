<?php
// NOTE: Ensure 'db.php' contains your database connection logic ($mysqli variable)
require 'db.php';
if (session_status() === PHP_SESSION_NONE) { session_start(); }

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function redirect_with($params = []) {
    $base = 'create_invoice.php';
    if (!empty($params)) $base .= '?' . http_build_query($params);
    header("Location: $base");
    exit;
}

// ---------- Configuration & Initial Setup ----------
$months_to_show = 6; // Show last 6 months in the dropdown

// Get initial filter values from GET or set defaults
$client_id = isset($_GET['client_id']) ? (int)$_GET['client_id'] : 0;
$invoice_month = $_GET['invoice_month'] ?? date('Y-m'); // YYYY-MM format

// Fetch client list
$clients = [];
$result = $mysqli->query("SELECT client_id, client_name FROM client_master ORDER BY client_name ASC");
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $clients[] = $row;
    }
}

// Generate Invoice Number 
$month_num = (int)date('m');
$year = (int)date('y');
$invoice_seq = ($month_num >= 4) ? $year + 1 : $year;  
$fy = $invoice_seq;

$sql = "SELECT invoice_no FROM client_invoice WHERE invoice_no LIKE 'TPS$fy/%' ORDER BY invoice_id DESC LIMIT 1";
$res = $mysqli->query($sql);

$nextSeq = 1;
if ($res && $row = $res->fetch_assoc()) {
    $parts = explode('/', $row['invoice_no']);
    if (isset($parts[1])) {
        $nextSeq = (int)$parts[1] + 1;
    }
}
$seqStr = str_pad($nextSeq, 5, '0', STR_PAD_LEFT);
$invoice_no = "TPS{$fy}/" . $seqStr;

// Generate month options (YYYY-MM format)
$month_options = [];
for ($i = 0; $i < $months_to_show; $i++) {
    $ts = strtotime("-$i months");
    $month_options[date('Y-m', $ts)] = date('F Y', $ts);
}

// ---------- AJAX Handler to Fetch Job Data ----------

if (isset($_GET['action']) && $_GET['action'] === 'fetch_job_data' && isset($_GET['client']) && isset($_GET['month'])) {
    header('Content-Type: application/json');
    $client_id_ajax = (int)$_GET['client'];
    $month_ajax = $_GET['month']; // YYYY-MM

    if ($client_id_ajax === 0 || !preg_match('/^\d{4}-\d{2}$/', $month_ajax)) {
        echo json_encode(['success' => false, 'message' => 'Invalid parameters.']);
        exit;
    }

    // Determine the date range for the selected month
    $start_date = $month_ajax . '-01';
    $end_date = date('Y-m-t', strtotime($start_date));

    // SQL to aggregate data from completed job stages
    $sql_fetch = "
        SELECT 
            SUM(T1.word_count) AS ce_count,
            SUM(T2.pages) AS pag_count,
            SUM(T1.eq) AS math_count, 
            SUM(T1.ref) AS ref_count
        FROM jobs j
        -- Only count if CE status is 'Completed'
        LEFT JOIN ce T1 ON j.job_id = T1.job_id AND T1.status = 'Completed'
        -- Only count if PAG status is 'Completed'
        LEFT JOIN pag T2 ON j.job_id = T2.job_id AND T2.status = 'Completed'
        WHERE j.client_id = ?
        AND j.job_date >= ? AND j.job_date <= ?
    ";

    $stmt = $mysqli->prepare($sql_fetch);
    $stmt->bind_param("iss", $client_id_ajax, $start_date, $end_date);
    $stmt->execute();
    $result = $stmt->get_result();
    $data = $result->fetch_assoc();
    $stmt->close();

    // Prepare data for JSON response, ensuring NULLs become 0
    $response_data = [
        'ce' => (int)($data['ce_count'] ?? 0),
        'pag' => (int)($data['pag_count'] ?? 0),
        'math' => (int)($data['math_count'] ?? 0),
        'ref_img' => (int)($data['ref_count'] ?? 0),
    ];

    echo json_encode(['success' => true, 'data' => $response_data]);
    exit;
}

// ---------- Handle Form Submission (POST) ----------

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
    $invoice_month  = $_POST['invoice_month'] ?? date('Y-m'); 

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
        $new_invoice_id = $stmt->insert_id; 
        
        // Redirect to generate the invoice, passing the invoice ID and month
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
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
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
                        <select name="client_id" id="client_id" class="form-select" required>
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
                    <label class="form-label small">Invoice Month</label>
                    <select name="invoice_month" id="invoice_month" class="form-select form-select-sm">
                        <?php foreach ($month_options as $value => $label): ?>
                            <option value="<?= h($value) ?>" 
                                <?= ($invoice_month == $value) ? 'selected' : '' ?>>
                                <?= h($label) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="col-md-6">
                    <label class="form-label small">Invoice No <span class="text-danger">*</span></label>
                    <input type="text" name="invoice_no" value="<?= h($invoice_no) ?>" class="form-control form-control-sm" required readonly>
                </div>
                <div class="col-md-6">
                    <label class="form-label small">Date</label>
                    <input type="date" name="invoice_date" class="form-control form-control-sm" value="<?= date('Y-m-d') ?>">
                </div>
                
                <hr class="mt-4 mb-3">
                <p class="small text-muted mb-0">Job Counts (Auto-populated by Client/Month)</p>

                <div class="col-md-2">
                    <label class="form-label small">CE (Word Count)</label>
                    <input type="number" id="ce" name="ce" class="form-control form-control-sm" value="0">
                </div>
                <div class="col-md-2">
                    <label class="form-label small">PAG (Pages)</label>
                    <input type="number" id="pag" name="pag" class="form-control form-control-sm" value="0">
                </div>
                <div class="col-md-2">
                    <label class="form-label small">XML (Count)</label>
                    <input type="number" id="xml" name="xml" class="form-control form-control-sm" value="0">
                </div>
                <div class="col-md-2">
                    <label class="form-label small">MATH (Eq Count)</label>
                    <input type="number" id="math" name="math" class="form-control form-control-sm" value="0">
                </div>
                <div class="col-md-2">
                    <label class="form-label small">REF (Ref Count)</label>
                    <input type="number" id="ref_img" name="ref_img" class="form-control form-control-sm" value="0">
                </div>
                
                <hr class="mt-4 mb-3">
                <p class="small text-muted mb-0">Financials</p>
                
                <div class="col-md-4">
                    <label class="form-label small">Price / Page (or Unit)</label>
                    <input type="number" step="0.01" id="price_per_page" name="price_per_page" class="form-control form-control-sm" required>
                </div>
                <div class="col-md-4">
                    <label class="form-label small">Total Amount (Before Tax)</label>
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
<script src="https://code.jquery.com/jquery-3.7.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
// Base URL for AJAX requests
const AJAX_URL = 'create_invoice.php?action=fetch_job_data';
const countFields = ["ce", "pag", "math", "ref_img"];

function calculateTotals() {
    let ce = parseInt(document.getElementById("ce").value) || 0;
    let pag = parseInt(document.getElementById("pag").value) || 0;
    let xml = parseInt(document.getElementById("xml").value) || 0;
    let math = parseInt(document.getElementById("math").value) || 0;
    let refImg = parseInt(document.getElementById("ref_img").value) || 0;
    let price = parseFloat(document.getElementById("price_per_page").value) || 0;
    let taxPercent = parseFloat(document.getElementById("tax").value) || 0;

    // Total units for calculation (sum of all count fields)
    let totalUnits = ce + pag + xml + math + refImg; 

    // Total amount
    let total = totalUnits * price;
    document.getElementById("total").value = total.toFixed(2);

    // Tax amount
    let taxAmount = (total * taxPercent) / 100;

    // Net total = total + tax
    let netTotal = total + taxAmount;
    document.getElementById("net_total").value = netTotal.toFixed(2);
}

function clearAndRecalculate(ids) {
    ids.forEach(id => {
        const input = document.getElementById(id);
        if (input) input.value = 0;
    });
    calculateTotals();
}

function fetchJobData() {
    const clientId = $('#client_id').val();
    const invoiceMonth = $('#invoice_month').val();

    if (!clientId || !invoiceMonth) {
        // Clear counts if client or month is not selected
        clearAndRecalculate(countFields);
        return;
    }

    // Indicate loading state
    countFields.forEach(id => {
        $('#' + id).val('...');
    });
    calculateTotals(); 


    $.ajax({
        url: AJAX_URL,
        method: 'GET',
        data: {
            client: clientId,
            month: invoiceMonth
        },
        dataType: 'json',
        success: function(response) {
            if (response.success && response.data) {
                // Map the data to the form fields, ensuring they are integers/0
                $('#ce').val(parseInt(response.data.ce) || 0);
                $('#pag').val(parseInt(response.data.pag) || 0);
                $('#math').val(parseInt(response.data.math) || 0);
                $('#ref_img').val(parseInt(response.data.ref_img) || 0);
                
                // Clear XML count (assumed to be manual unless logic added)
                $('#xml').val(0); 
                
                calculateTotals();
            } else {
                console.error("Error fetching job data:", response.message);
                clearAndRecalculate(countFields);
            }
        },
        error: function(xhr, status, error) {
            console.error("AJAX Error:", status, error);
            alert("Could not load job counts. Check network and server logs.");
            clearAndRecalculate(countFields);
        },
        complete: function() {
            // Ensure final calculation
            calculateTotals();
        }
    });
}

// Attach listeners for manual input calculation
["ce", "pag", "xml", "math", "ref_img", "price_per_page", "tax"].forEach(id => {
    document.getElementById(id).addEventListener("input", calculateTotals);
});

// Attach listeners for auto-fetch on dropdown change
$('#client_id, #invoice_month').on('change', fetchJobData);

// Initial calc on page load
$(document).ready(function() {
    calculateTotals();

    // Initial data fetch if client/month are pre-selected (from GET parameters)
    if ($('#client_id').val() && $('#invoice_month').val()) {
        fetchJobData();
    }
});
</script>

</body>
</html>