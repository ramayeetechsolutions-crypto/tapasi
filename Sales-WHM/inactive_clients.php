<?php
require 'db.php'; 
// Enable error reporting for debugging
ini_set('display_errors', 1);
error_reporting(E_ALL);

// Start session if not started
if (session_status() === PHP_SESSION_NONE) { session_start(); }

// ---------------------------------------------------------
// HELPER FUNCTIONS
// ---------------------------------------------------------
function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

function redirect_with($params = []) {
    $base = 'inactive_clients.php';
    if (!empty($params)) $base .= '?' . http_build_query($params);
    echo "<script>window.location.href='$base';</script>";
    exit;
}

// ---------------------------------------------------------
// HANDLE IMPORT LOGIC (XLSX Format)
// ---------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'import_excel') {
    
    if (isset($_FILES['excel_file']) && $_FILES['excel_file']['error'] === 0) {
        
        // 1. Locate the SimpleXLSX Library
        $libPath = dirname(__DIR__) . '/simplexlsx-master/src/SimpleXLSX.php';
        // Fallback paths just in case
        if (!file_exists($libPath)) $libPath = dirname(__DIR__) . '/simplexlsx-master/SimpleXLSX.php';
        if (!file_exists($libPath)) $libPath = 'simplexlsx-master/src/SimpleXLSX.php';

        if (!file_exists($libPath)) {
            redirect_with(['msg' => 'Library file not found.', 'type' => 'danger']);
        }
        
        require_once $libPath;

        // 2. Initialize Parser
        $xlsx = null;
        if (class_exists('Shuchkin\SimpleXLSX')) {
            $xlsx = Shuchkin\SimpleXLSX::parse($_FILES['excel_file']['tmp_name']);
        } elseif (class_exists('SimpleXLSX')) {
            $xlsx = SimpleXLSX::parse($_FILES['excel_file']['tmp_name']);
        } else {
            redirect_with(['msg' => 'Class SimpleXLSX not found.', 'type' => 'danger']);
        }

        // 3. Process Rows
        if ($xlsx) {
            $rows = $xlsx->rows();
            $updated_count = 0;
            $skipped_count = 0;
            
            foreach ($rows as $k => $r) {
                if ($k === 0) continue; // Skip Header Row

                // --- 1. CORRECT COLUMN MAPPING (Based on your Screenshot) ---
                // A[0]=id, B[1]=client_id, C[2]=Name
                
                $client_name = trim($r[2] ?? ''); 
                
                if (empty($client_name)) continue;

                // Map rest of columns
                $country     = $mysqli->real_escape_string($r[3] ?? '');
                $currency    = $mysqli->real_escape_string($r[4] ?? '');
                $journal     = $mysqli->real_escape_string($r[5] ?? '');
                $freq        = $mysqli->real_escape_string($r[6] ?? '');
                $year_stop   = $mysqli->real_escape_string($r[7] ?? '');
                $last_m_y    = $mysqli->real_escape_string($r[8] ?? '');
                $reason      = $mysqli->real_escape_string($r[9] ?? '');
                $ce_pag      = $mysqli->real_escape_string($r[10] ?? '');
                
                // Date Handling (Column L / Index 11)
                $raw_date    = $r[11] ?? null; 
                $last_date   = NULL;
                if (!empty($raw_date)) {
                    // Handle Excel Numeric Date or String Date
                    if (is_numeric($raw_date)) {
                        // Excel date to PHP date
                        $last_date = date('Y-m-d', \Shuchkin\SimpleXLSX::unixstamp($raw_date));
                    } else {
                        // Standard String date
                        $ts = strtotime($raw_date);
                        if ($ts !== false) $last_date = date('Y-m-d', $ts);
                    }
                }

                $pag         = $mysqli->real_escape_string($r[12] ?? '');
                $status      = 'Inactive';

                // --- 2. CHECK IF EXISTS IN MASTER ---
                $checkStmt = $mysqli->prepare("SELECT client_id FROM client_master WHERE client_name = ?");
                $checkStmt->bind_param("s", $client_name);
                $checkStmt->execute();
                $checkRes = $checkStmt->get_result();
                
                if ($checkRes->num_rows > 0) {
                    // === CLIENT EXISTS ===
                    $row = $checkRes->fetch_assoc();
                    $cid = $row['client_id'];
                    $checkStmt->close();

                    // A. UPDATE client_master
                    $sqlUpd = "UPDATE client_master SET 
                               country = ?, currency = ?, journal_name = ?, frequency = ?, 
                               year_stopped = ?, last_received_month_year = ?, reason = ?, 
                               ce_pag = ?, last_file_receive_date = ?, pag = ?, status = ? 
                               WHERE client_id = ?";

                    if ($upd = $mysqli->prepare($sqlUpd)) {
                        $dateParam = $last_date ? $last_date : NULL;
                        
                        $upd->bind_param("sssssssssssi", 
                            $country, $currency, $journal, $freq, $year_stop, 
                            $last_m_y, $reason, $ce_pag, $dateParam, $pag, $status, $cid
                        );
                        
                        if ($upd->execute()) {
                            $updated_count++;

                            // B. INSERT INTO inactive_clients_data (THE FIX: Insert ALL fields)
                            $sqlLog = "INSERT INTO inactive_clients_data 
                                      (client_id, client_name, country, currency, journal_name, frequency, 
                                       year_stopped, last_received_month_year, reason, ce_pag, 
                                       last_file_receive_date, pag) 
                                      VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
                            
                            if ($stmtLog = $mysqli->prepare($sqlLog)) {
                                $stmtLog->bind_param("isssssssssss", 
                                    $cid, $client_name, $country, $currency, $journal, $freq, 
                                    $year_stop, $last_m_y, $reason, $ce_pag, $dateParam, $pag
                                );
                                $stmtLog->execute();
                                $stmtLog->close();
                            }
                        }
                        $upd->close();
                    }
                } else {
                    // === CLIENT DOES NOT EXIST -> SKIP ===
                    $skipped_count++;
                    $checkStmt->close();
                }
            }
            
            $msg = "Process Complete. <br><strong>Updated & Logged:</strong> $updated_count clients. <br><strong>Skipped (Not in Master):</strong> $skipped_count rows.";
            $type = ($updated_count > 0) ? 'success' : 'warning';
            redirect_with(['msg' => $msg, 'type' => $type]);

        } else {
            $err = (method_exists('Shuchkin\SimpleXLSX', 'parseError')) ? Shuchkin\SimpleXLSX::parseError() : 'Unknown Error';
            redirect_with(['msg' => 'SimpleXLSX Parse Error: ' . $err, 'type' => 'danger']);
        }
    } else {
        redirect_with(['msg' => 'No file uploaded or file error.', 'type' => 'danger']);
    }
}

// ---------------------------------------------------------
// 2. FETCH DATA (Only Inactive Clients from Master)
// ---------------------------------------------------------
// Fixed SQL query with proper table aliases and explicit column selection
$sql = "SELECT 
            cm.client_id,
            cm.client_name,
            cm.country,
            cm.currency,
            cm.journal_name,
            cm.frequency,
            cm.reason,
            cm.year_stopped,
            cm.last_received_month_year,
            cm.last_file_receive_date,
            cm.status,
            cm.pag,
            cm.ce_pag,
            icd.created_at as inactive_date,
            GROUP_CONCAT(cs.service_name SEPARATOR ', ') as services
        FROM client_master cm
        LEFT JOIN inactive_clients_data icd ON cm.client_id = icd.client_id
        LEFT JOIN client_services cs ON cm.client_id = cs.client_id AND cs.is_active = 1
        WHERE cm.status = 'Inactive' 
        GROUP BY cm.client_id
        ORDER BY cm.client_id DESC";

$result = $mysqli->query($sql);

require 'header.php'; 
?>

<div class="container-fluid py-4" style="margin-top: 60px;">
    
    <!-- PAGE HEADER -->
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h3 class="fw-bold text-dark mb-0"><i class="fas fa-user-times me-2"></i>Inactive Clients</h3>
            <p class="text-muted">Import Excel to update existing clients to Inactive status.</p>
        </div>
        
        <!-- IMPORT BUTTON -->
        <button type="button" class="btn btn-warning text-dark fw-bold" data-bs-toggle="modal" data-bs-target="#importModal">
            <i class="fas fa-file-excel me-2"></i> Update from Excel
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
                <table class="table table-hover table-striped mb-0 text-center align-middle" style="font-size: 0.85rem;">
                    <thead class="bg-dark text-white">
                        <tr>
                            <th>Client Name</th>
                            <th>Country</th>
                            <th>Currency</th>
                            <th>Journal</th>
                            <th>Services</th>
                            <th>Frequency</th>
                            
                            <th>Reason</th>
                            <th>Year Stopped</th>
                            <th>Last Rec (M/Y)</th>
                            <th>Last Date</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($result && $result->num_rows > 0): ?>
                            <?php while ($row = $result->fetch_assoc()): ?>
                            <tr>
                                <td class="fw-bold text-start">
                                    <?= h($row['client_name']) ?><br>
                                    <small class="text-muted fw-normal">ID: <?= h($row['client_id']) ?></small>
                                </td>
                                <td><?= h($row['country']) ?></td>
                                <td><?= h($row['currency']) ?></td>
                                <td><?= h($row['journal_name']) ?></td>
                                 <td class="text-start" style="max-width: 150px; white-space: normal;">
                                    <?php 
                                    if (!empty($row['services'])) {
                                        $services = explode(', ', $row['services']);
                                        foreach ($services as $service) {
                                            echo '<span class="badge bg-info me-1 mb-1">' . h(trim($service)) . '</span>';
                                        }
                                    } else {
                                        echo '-';
                                    }
                                    ?>
                                </td>

                                <td><?= h($row['frequency']) ?></td>
                                <td class="text-danger text-start" style="max-width: 200px; white-space: normal;">
                                    <?= h($row['reason']) ?>
                                </td>
                                <td><span class="badge bg-secondary"><?= h($row['year_stopped']) ?></span></td>
                                <td><?= h($row['last_received_month_year']) ?></td>
                                <td><?= (!empty($row['last_file_receive_date']) && $row['last_file_receive_date'] != '0000-00-00') ? date('d-m-Y', strtotime($row['last_file_receive_date'])) : '-' ?></td>
                                <td>
                                    <span class="badge bg-danger">Inactive</span><br>
                                    <small class="text-muted"><?= (!empty($row['inactive_date'])) ? date('d-m-Y', strtotime($row['inactive_date'])) : '' ?></small>
                                </td>
                            </tr>
                            <?php endwhile; ?>
                        <?php else: ?>
                            <tr><td colspan="11" class="text-center py-5 text-muted">No inactive clients found.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<!-- IMPORT MODAL POPUP -->
<div class="modal fade" id="importModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-warning">
                <h5 class="modal-title fw-bold"><i class="fas fa-sync-alt me-2"></i>Update Inactive Clients</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            
            <form action="" method="post" enctype="multipart/form-data">
                <input type="hidden" name="action" value="import_excel">
                
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label fw-bold">Select Excel File (.xlsx)</label>
                        <input type="file" name="excel_file" class="form-control" accept=".xlsx" required>
                    </div>
                    
                    <div class="alert alert-light border small">
                        <strong><i class="fas fa-exclamation-triangle"></i> Rules:</strong><br>
                        1. <strong>Existed Clients:</strong> Will be UPDATED in Master & Saved to History.<br>
                        2. <strong>New Clients:</strong> Will be SKIPPED (Not imported).
                    </div>
                </div>
                
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                    <button type="submit" class="btn btn-dark"><i class="fas fa-upload me-1"></i> Update Data</button>
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