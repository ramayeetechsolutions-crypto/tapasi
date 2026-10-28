<?php
require 'db.php'; 
ini_set('display_errors', 1);
error_reporting(E_ALL);
if (session_status() === PHP_SESSION_NONE) { session_start(); }

// --- HELPER FUNCTIONS ---
function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function redirect_with($params = []) {
    $base = 'extra_charges.php';
    if (!empty($params)) $base .= '?' . http_build_query($params);
    echo "<script>window.location.href='$base';</script>";
    exit;
}
function parseExcelDate($val) {
    if(empty($val)) return NULL;
    $ts = strtotime($val);
    return ($ts !== false) ? date('Y-m-d', $ts) : NULL;
}

// ---------------------------------------------------------
// STRICT IMPORT LOGIC (Skips Unknown Clients)
// ---------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'import_excel') {
    
    if (isset($_FILES['excel_file']) && $_FILES['excel_file']['error'] === 0) {
        
        // --- Locate Library ---
        $libPath = dirname(__DIR__) . '/simplexlsx-master/src/SimpleXLSX.php';
        if (!file_exists($libPath)) $libPath = dirname(__DIR__) . '/simplexlsx-master/SimpleXLSX.php';
        if (!file_exists($libPath)) $libPath = 'simplexlsx-master/src/SimpleXLSX.php';

        if (!file_exists($libPath)) { die("Library not found"); }
        
        require_once $libPath;
        $xlsx = Shuchkin\SimpleXLSX::parse($_FILES['excel_file']['tmp_name']);

        if ($xlsx) {
            $rows = $xlsx->rows();
            
            // Debug Counters
            $count_added = 0;
            $count_skipped_dupe = 0;
            $count_skipped_unknown = 0;
            $debug_log = "";

            foreach ($rows as $k => $r) {
                if ($k === 0) continue; // Skip Header

                // Col 2 is Client Name
                $client_name = trim($mysqli->real_escape_string($r[2] ?? '')); 
                if (empty($client_name)) continue; 

                // ---------------------------------------------------
                // 1. STRICT CHECK: DOES CLIENT EXIST?
                // ---------------------------------------------------
                $client_id = 0;
                $check = $mysqli->query("SELECT client_id FROM client_master WHERE TRIM(client_name) = '$client_name'");
                
                if ($check && $check->num_rows > 0) {
                    $row = $check->fetch_assoc();
                    $client_id = $row['client_id'];
                } else {
                    // *** REJECTION LOGIC ***
                    // If client is not found, SKIP this row immediately.
                    // Do not save to history. Do not create new client.
                    $count_skipped_unknown++;
                    $debug_log .= "Row $k: Skipped '$client_name' (Not found in Master)<br>";
                    continue; 
                }

                // 2. PREPARE DATA
                $sales_inc      = $mysqli->real_escape_string($r[0] ?? '');
                $send_date      = parseExcelDate($r[1] ?? '');
                $article_det    = $mysqli->real_escape_string($r[3] ?? '');
                $art_count      = (int)($r[4] ?? 0);
                $particular     = $mysqli->real_escape_string($r[5] ?? '');
                $process        = $mysqli->real_escape_string($r[6] ?? '');
                $charges        = (float)($r[7] ?? 0);
                $cli_conf_date  = parseExcelDate($r[8] ?? '');
                $send_conf_team = $mysqli->real_escape_string($r[9] ?? '');
                $team_conf_date = parseExcelDate($r[10] ?? '');
                $remarks        = $mysqli->real_escape_string($r[11] ?? '');

                $d1 = $send_date ? "'$send_date'" : "NULL";
                $d2 = $cli_conf_date ? "'$cli_conf_date'" : "NULL";
                $d3 = $team_conf_date ? "'$team_conf_date'" : "NULL";

                // 3. DUPLICATE CHECK (Prevent double entry)
                $dupCheck = $mysqli->query("SELECT id FROM extra_charges WHERE client_name = '$client_name' AND article_details = '$article_det' AND particular = '$particular' LIMIT 1");
                
                if ($dupCheck && $dupCheck->num_rows > 0) {
                    $count_skipped_dupe++;
                    continue;
                }

                // 4. INSERT INTO EXTRA CHARGES (History)
                $sql = "INSERT INTO extra_charges 
                (client_id, client_name, sales_incharge, send_date_client, article_details, article_count, particular, process, charges, client_confirm_date, send_confirm_team, confirm_date_team, remarks, created_at) 
                VALUES 
                ('$client_id', '$client_name', '$sales_inc', $d1, '$article_det', '$art_count', '$particular', '$process', '$charges', $d2, '$send_conf_team', $d3, '$remarks', NOW())";
                
                if($mysqli->query($sql)) {
                    $count_added++;

                    // 5. UPDATE CLIENT MASTER
                    // We know client_id exists because of Step 1
                    $updSql = "UPDATE client_master SET 
                               ec_sales_incharge = '$sales_inc',
                               ec_send_date_client = $d1,
                               ec_article_details = '$article_det',
                               ec_article_count = '$art_count',
                               ec_particular = '$particular',
                               ec_process = '$process',
                               ec_charges = '$charges',
                               ec_client_confirm_date = $d2,
                               ec_send_confirm_team = '$send_conf_team',
                               ec_confirm_date_team = $d3,
                               ec_remarks = '$remarks'
                               WHERE client_id = '$client_id'";
                    $mysqli->query($updSql);
                }
            }
            
            // SHOW REPORT
            echo "<div style='padding:20px; font-family:sans-serif;'>";
            echo "<h3>Import Summary</h3>";
            echo "<ul>";
            echo "<li style='color:green'><strong>Successfully Added & Updated:</strong> $count_added</li>";
            echo "<li style='color:orange'><strong>Skipped (Duplicates):</strong> $count_skipped_dupe</li>";
            echo "<li style='color:red'><strong>Skipped (Client Not Found in Master):</strong> $count_skipped_unknown</li>";
            echo "</ul>";
            if($debug_log) {
                echo "<hr><strong>Unknown Clients Details:</strong><br><small>$debug_log</small>";
            }
            echo "<br><br><a href='extra_charges.php' style='background:#333; color:#fff; padding:10px 20px; text-decoration:none;'>Go Back</a>";
            echo "</div>";
            exit; 
        }
    }
}
// ---------------------------------------------------------
// FETCH DATA
// ---------------------------------------------------------
$sql = "SELECT * FROM extra_charges ORDER BY id DESC";
$result = $mysqli->query($sql);

require 'header.php'; 
?>

<div class="container-fluid py-4" style="margin-top: 60px;">
    
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h3 class="fw-bold text-dark mb-0"><i class="fas fa-hand-holding-usd me-2"></i>Extra Charges</h3>
            <p class="text-muted">Track additional client charges and status</p>
        </div>
        <button type="button" class="btn btn-danger fw-bold" data-bs-toggle="modal" data-bs-target="#importModal">
            <i class="fas fa-file-excel me-2"></i> Import Extra Charges
        </button>
    </div>

    <?php if (!empty($_GET['msg'])): ?>
    <div class="alert alert-<?= h($_GET['type'] ?? 'info') ?> alert-dismissible fade show" role="alert">
        <?= $_GET['msg'] ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    <?php endif; ?>

    <div class="card shadow border-0">
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover table-bordered mb-0 align-middle text-center" style="font-size: 0.8rem; white-space: nowrap;">
                    <thead class="bg-dark text-white">
                        <tr>
                            <th>Sales InCharge</th>
                            <th>Send Date</th>
                            <th>Client Name</th>
                            <th>Article/Journal</th>
                            <th>Count</th>
                            <th>Particular</th>
                            <th>Process</th>
                            <th>Charges</th>
                            <th>Client Confirmed</th>
                            <th>Team Status</th>
                            <th>Team Confirmed</th>
                            <th>Remarks</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($result && $result->num_rows > 0): ?>
                            <?php while ($row = $result->fetch_assoc()): ?>
                            <tr>
                                <td><?= h($row['sales_incharge']) ?></td>
                                <td><?= ($row['send_date_client']) ? date('d-m-Y', strtotime($row['send_date_client'])) : '-' ?></td>
                                <td class="fw-bold text-start">
                                    <?= h($row['client_name']) ?>
                                    <?php if($row['client_id'] > 0): ?>
                                        <i class="fas fa-link text-success ms-1 small"></i>
                                    <?php endif; ?>
                                </td>
                                <td><?= h($row['article_details']) ?></td>
                                <td><?= h($row['article_count']) ?></td>
                                <td><?= h($row['particular']) ?></td>
                                <td><?= h($row['process']) ?></td>
                                <td class="fw-bold text-danger"><?= number_format((float)$row['charges'], 2) ?></td>
                                <td><?= ($row['client_confirm_date']) ? date('d-m-Y', strtotime($row['client_confirm_date'])) : '-' ?></td>
                                <td><?= h($row['send_confirm_team']) ?></td>
                                <td><?= ($row['confirm_date_team']) ? date('d-m-Y', strtotime($row['confirm_date_team'])) : '-' ?></td>
                                <td style="max-width: 150px; overflow: hidden; text-overflow: ellipsis;"><?= h($row['remarks']) ?></td>
                            </tr>
                            <?php endwhile; ?>
                        <?php else: ?>
                            <tr><td colspan="12" class="text-center py-5 text-muted">No extra charges records found.</td></tr>
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
            <div class="modal-header bg-danger text-white">
                <h5 class="modal-title"><i class="fas fa-upload me-2"></i>Import Extra Charges</h5>
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
                    <button type="submit" class="btn btn-danger">Upload & Process</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<!-- ADD THIS SCRIPT HERE -->
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