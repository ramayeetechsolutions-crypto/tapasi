<?php
require 'db.php'; // Ensure database connection
ini_set('display_errors', 1);
error_reporting(E_ALL);
if (session_status() === PHP_SESSION_NONE) { session_start(); }

// ---------------------------------------------------------
// HELPER FUNCTIONS
// ---------------------------------------------------------
function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

function redirect_with($params = []) {
    $base = 'invoice_data.php';
    if (!empty($params)) $base .= '?' . http_build_query($params);
    echo "<script>window.location.href='$base';</script>";
    exit;
}

// ---------------------------------------------------------
// 1. HANDLE IMPORT LOGIC (STRICT MODE: Existing Clients Only)
// ---------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'import_excel') {
    
    if (isset($_FILES['excel_file']) && $_FILES['excel_file']['error'] === 0) {
        
        $libPath = dirname(__DIR__) . '/simplexlsx-master/src/SimpleXLSX.php';
        if (!file_exists($libPath)) $libPath = dirname(__DIR__) . '/simplexlsx-master/SimpleXLSX.php';
        if (!file_exists($libPath)) $libPath = 'simplexlsx-master/src/SimpleXLSX.php';

        if (!file_exists($libPath)) { redirect_with(['msg' => 'Library not found.', 'type' => 'danger']); }
        
        require_once $libPath;
        $xlsx = Shuchkin\SimpleXLSX::parse($_FILES['excel_file']['tmp_name']);

        if ($xlsx) {
            $rows = $xlsx->rows();
            $count = 0;
            $updated_master = 0;
            $skipped_dupe = 0;
            $skipped_unknown = 0; // New counter for unknown clients
            $errors = 0;
            
            foreach ($rows as $k => $r) {
                if ($k === 0) continue; // Skip Header

                $client_name = trim($mysqli->real_escape_string($r[0] ?? ''));
                if (empty($client_name)) continue; 

                // =========================================================
                // 1. STRICT CHECK: DOES CLIENT EXIST IN MASTER?
                // =========================================================
                $client_id = 0;
                $idCheck = $mysqli->query("SELECT client_id FROM client_master WHERE TRIM(client_name) = '$client_name' LIMIT 1");
                
                if ($idCheck && $idCheck->num_rows > 0) {
                    $row = $idCheck->fetch_assoc();
                    $client_id = $row['client_id'];
                } else {
                    // *** REJECT UNKNOWN CLIENT ***
                    $skipped_unknown++;
                    continue; // Skip to next row immediately
                }

                // =========================================================
                // 2. DUPLICATE CHECK (Invoice No + Client)
                // =========================================================
                $inv_no = trim($mysqli->real_escape_string($r[1] ?? ''));
                
                $dupCheck = $mysqli->query("SELECT id FROM invoice_data WHERE client_name = '$client_name' AND invoice_no = '$inv_no' LIMIT 1");
                if ($dupCheck && $dupCheck->num_rows > 0) {
                    $skipped_dupe++;
                    continue; // Skip duplicate
                }

                // Sanitize Inputs
                $fmt        = $mysqli->real_escape_string($r[2] ?? '');
                $pdf        = $mysqli->real_escape_string($r[3] ?? '');
                $comp       = $mysqli->real_escape_string($r[4] ?? '');
                $add1       = $mysqli->real_escape_string($r[5] ?? '');
                $add2       = $mysqli->real_escape_string($r[6] ?? '');
                $add3       = $mysqli->real_escape_string($r[7] ?? '');
                $c_gst      = $mysqli->real_escape_string($r[8] ?? '');
                $desc       = $mysqli->real_escape_string($r[9] ?? '');
                $ce_pag     = $mysqli->real_escape_string($r[10] ?? '');
                $xml        = $mysqli->real_escape_string($r[11] ?? '');
                $math       = $mysqli->real_escape_string($r[12] ?? '');
                $ref        = $mysqli->real_escape_string($r[13] ?? '');
                $pp_rate    = (float)($r[14] ?? 0);
                $total      = (float)($r[15] ?? 0);
                $tax        = (float)($r[16] ?? 0);
                $g_total    = (float)($r[17] ?? 0);
                $tat        = $mysqli->real_escape_string($r[18] ?? '');
                $country    = $mysqli->real_escape_string($r[19] ?? '');
                $gst_rate   = $mysqli->real_escape_string($r[20] ?? '');

                // --- 3. INSERT INTO INVOICE_DATA ---
                $sql = "INSERT INTO invoice_data 
                (client_id, client_name, invoice_no, invoice_format, pdf_name, company_name, add1, add2, add3, client_gst, description, ce_pag, xml_val, math_val, ref_img, price_per_page, total, tax, grand_total, tat, country, gst_rate, created_at) 
                VALUES 
                ('$client_id', '$client_name', '$inv_no', '$fmt', '$pdf', '$comp', '$add1', '$add2', '$add3', '$c_gst', '$desc', '$ce_pag', '$xml', '$math', '$ref', '$pp_rate', '$total', '$tax', '$g_total', '$tat', '$country', '$gst_rate', NOW())";

                if ($mysqli->query($sql)) {
                    $count++;

                    // --- 4. UPDATE CLIENT_MASTER ---
                    // We know client_id exists because we checked in Step 1
                    $updSql = "UPDATE client_master SET 
                               invoice_no = '$inv_no',
                               invoice_format = '$fmt',
                               pdf_name = '$pdf',
                               add1 = '$add1',
                               add2 = '$add2',
                               add3 = '$add3',
                               client_gst = '$c_gst',
                               description = '$desc',
                               ce_pag = '$ce_pag',
                               xml_val = '$xml',
                               math_val = '$math',
                               ref_img = '$ref',
                               price_per_page = '$pp_rate',
                               total = '$total',
                               tax = '$tax',
                               grand_total = '$g_total',
                               tat = '$tat',
                               gst_rate = '$gst_rate'
                               WHERE client_id = '$client_id'";
                    
                    if ($mysqli->query($updSql)) {
                        $updated_master++;
                    }
                } else {
                    $errors++;
                }
            }
            
            $msg = "Import Complete.<br>Added: <strong>$count</strong><br>Skipped (Duplicates): <strong>$skipped_dupe</strong><br>Skipped (Unknown Clients): <strong>$skipped_unknown</strong>";
            redirect_with(['msg' => $msg, 'type' => 'success']);

        } else {
            redirect_with(['msg' => 'SimpleXLSX Parse Error', 'type' => 'danger']);
        }
    } else {
        redirect_with(['msg' => 'No file uploaded.', 'type' => 'danger']);
    }
}
// ---------------------------------------------------------
// 2. FETCH DATA FOR DISPLAY
// ---------------------------------------------------------
$sql = "SELECT * FROM invoice_data ORDER BY id DESC";
$result = $mysqli->query($sql);

require 'header.php'; 
?>

<div class="container-fluid py-4" style="margin-top: 60px;">
    
    <!-- HEADER -->
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h3 class="fw-bold text-dark mb-0"><i class="fas fa-file-invoice-dollar me-2"></i>Invoice Data</h3>
            <p class="text-muted">Manage uploaded invoice records. (Duplicates are skipped)</p>
        </div>
        <button type="button" class="btn btn-primary fw-bold" data-bs-toggle="modal" data-bs-target="#importModal">
            <i class="fas fa-file-excel me-2"></i> Import Invoices
        </button>
    </div>

    <!-- ALERTS -->
    <?php if (!empty($_GET['msg'])): ?>
    <div class="alert alert-<?= h($_GET['type'] ?? 'info') ?> alert-dismissible fade show" role="alert">
        <?= $_GET['msg'] ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    <?php endif; ?>

    <!-- DATA TABLE -->
    <div class="card shadow border-0">
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover table-bordered mb-0 align-middle text-center" style="font-size: 0.8rem; white-space: nowrap;">
                    <thead class="bg-dark text-white">
                        <tr>
                            <th>Client Name</th>
                            <th>Inv. No</th>
                            <th>CE+PAG</th>
                            <th>XML</th>
                            <th>MATH</th>
                            <th>REF/IMG</th>
                            <th>Total</th>
                            <th>Grand Total</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($result && $result->num_rows > 0): ?>
                            <?php while ($row = $result->fetch_assoc()): ?>
                            <tr>
                                <td class="fw-bold text-start">
                                    <?= h($row['client_name']) ?>
                                    <?php if($row['client_id'] > 0): ?>
                                        <i class="fas fa-check-circle text-success ms-1" title="Linked"></i>
                                    <?php endif; ?>
                                </td>
                                <td><?= h($row['invoice_no']) ?></td>
                                <td><?= h($row['ce_pag']) ?></td>
                                <td><?= h($row['xml_val']) ?></td>
                                <td><?= h($row['math_val']) ?></td>
                                <td><?= h($row['ref_img']) ?></td>
                                <td><?= number_format((float)$row['total'], 2) ?></td>
                                <td class="fw-bold text-success"><?= number_format((float)$row['grand_total'], 2) ?></td>
                            </tr>
                            <?php endwhile; ?>
                        <?php else: ?>
                            <tr><td colspan="8" class="text-center py-5 text-muted">No invoice data found.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<!-- IMPORT MODAL -->
<div class="modal fade" id="importModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title"><i class="fas fa-upload me-2"></i>Import Invoice Data</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            
            <form action="" method="post" enctype="multipart/form-data">
                <input type="hidden" name="action" value="import_excel">
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label fw-bold">Select Excel File (.xlsx)</label>
                        <input type="file" name="excel_file" class="form-control" accept=".xlsx" required>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="submit" class="btn btn-primary">Upload & Process</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<!-- ADD THIS SCRIPT HERE FOR THE 2-SECOND TIMER -->
<script>
    document.addEventListener("DOMContentLoaded", function() {
        // Select the alert element
        var alertBox = document.querySelector('.alert');
        
        // If an alert exists on the page
        if (alertBox) {
            setTimeout(function() {
                // Use Bootstrap's built-in command to close it cleanly
                var alert = new bootstrap.Alert(alertBox);
                alert.close();
            }, 2000); // 2000 means 2 seconds
        }
    });
</script>

</body>
</html>