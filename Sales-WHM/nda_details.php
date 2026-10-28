<?php
require 'db.php'; 
ini_set('display_errors', 1);
error_reporting(E_ALL);
if (session_status() === PHP_SESSION_NONE) { session_start(); }

// ---------- Helpers ----------
function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function formatDate($d) {
    if (empty($d) || $d === '0000-00-00' || $d === '0000-00-00 00:00:00') return '—';
    return date('d-m-Y', strtotime($d));
}
function redirect_with($params = []) {
    $base = 'nda_details.php';
    if (!empty($params)) {
        $base .= '?' . http_build_query($params);
    }
    header("Location: $base");
    exit;
}

// ---------- Handle POST actions (Insert or Update NDA) ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'update_nda') {
        $client_id = (int)($_POST['client_id'] ?? 0);
        $nda_status = $mysqli->real_escape_string($_POST['nda_status'] ?? 'No');
        $revision_status = $mysqli->real_escape_string($_POST['revision_amount_status'] ?? 'No');
        $signed_date = $_POST['signed_date'] ?? NULL;

        // Handle empty date
        if (empty($signed_date)) {
            $sql_date = "NULL";
        } else {
            $sql_date = "'" . $mysqli->real_escape_string(date('Y-m-d', strtotime($signed_date))) . "'";
        }

        if ($client_id > 0) {
            // 1. Check if record exists in nda_details
            $checkStmt = $mysqli->query("SELECT id FROM nda_details WHERE client_id = $client_id");
            
            if ($checkStmt && $checkStmt->num_rows > 0) {
                // RECORD EXISTS -> UPDATE
                $sql = "UPDATE nda_details 
                        SET nda_status = '$nda_status', 
                            revision_amount_status = '$revision_status', 
                            signed_date = $sql_date 
                        WHERE client_id = $client_id";
                $msg = "NDA details updated successfully";
            } else {
                // NO RECORD -> INSERT NEW
                $sql = "INSERT INTO nda_details (client_id, nda_status, revision_amount_status, signed_date) 
                        VALUES ($client_id, '$nda_status', '$revision_status', $sql_date)";
                $msg = "New NDA record created successfully";
            }
            
            if ($mysqli->query($sql)) {
                redirect_with(['msg' => $msg, 'type' => 'success']);
            } else {
                redirect_with(['msg' => 'Database Error: ' . $mysqli->error, 'type' => 'danger']);
            }
        }
    }
}

// ---------- View / Edit Mode ----------
$mode = $_GET['mode'] ?? 'view';
$editClient = null;

// If clicking "Edit", fetch data using JOIN
if ($mode === 'edit') {
    $id = (int)($_GET['id'] ?? 0);
    if ($id > 0) {
        // FIXED: Correct column aliases in the query
        $sql = "SELECT 
                    cm.client_id, 
                    cm.client_name,
                    cm.company_name,
                    nd.nda_status, 
                    nd.revision_amount_status, 
                    nd.signed_date
                FROM client_master cm 
                LEFT JOIN nda_details nd ON cm.client_id = nd.client_id 
                WHERE cm.client_id = ?";
        
        $stmt = $mysqli->prepare($sql);
        if ($stmt) {
            $stmt->bind_param("i", $id);
            $stmt->execute();
            $result = $stmt->get_result();
            $editClient = $result->fetch_assoc();
            $stmt->close();
        }
    }
    if (!$editClient) $mode = 'view';
}

// ---------- Filters & Sorting ----------
$q = trim($_GET['q'] ?? '');
$sort = $_GET['sort'] ?? 'client_name';
$dir = strtolower($_GET['dir'] ?? 'asc');

// Validate sort columns (mapped to aliases)
$allowed_sort = ['client_name', 'company_name', 'nda_status', 'signed_date'];
if (!in_array($sort, $allowed_sort, true)) $sort = 'client_name';
$dir = ($dir === 'asc') ? 'asc' : 'desc';

// Mapping sort keys to actual DB columns
$sortMap = [
    'client_name' => 'cm.client_name',
    'company_name' => 'cm.company_name',
    'nda_status' => 'nda_status', // Use alias since we have IFNULL
    'signed_date' => 'nd.signed_date'
];
$orderBy = $sortMap[$sort];

// Build Query with LEFT JOIN - FIXED: Removed comment from SQL string
$sql = "SELECT 
            cm.client_id, 
            cm.client_name, 
            cm.company_name, 
            cm.email, 
            cm.phone, 
            cm.country,
            cm.status as client_status,
            IFNULL(nd.nda_status, 'No') as nda_status, 
            IFNULL(nd.revision_amount_status, 'No') as revision_amount_status, 
            nd.signed_date
        FROM client_master cm
        LEFT JOIN nda_details nd ON cm.client_id = nd.client_id";

$cond = []; 
$params = []; 
$types = '';

if ($q !== '') {
    $cond[] = "(cm.client_name LIKE ? OR cm.company_name LIKE ? OR cm.email LIKE ?)";
    $like = "%$q%";
    $params[] = $like; 
    $params[] = $like;
    $params[] = $like;
    $types .= "sss";
}

if ($cond) {
    $sql .= " WHERE " . implode(" AND ", $cond);
}
$sql .= " ORDER BY $orderBy $dir";

// Debug: Uncomment to see the SQL query
// echo "<pre>SQL Query: " . htmlspecialchars($sql) . "</pre>";
// exit;

$clients = false;
$error = '';

try {
    if (!empty($types)) {
        $stmt = $mysqli->prepare($sql);
        if ($stmt) {
            $stmt->bind_param($types, ...$params);
            $stmt->execute();
            $clients = $stmt->get_result();
        } else {
            $error = "Prepare failed: " . $mysqli->error;
        }
    } else {
        $clients = $mysqli->query($sql);
        if (!$clients) {
            $error = "Query failed: " . $mysqli->error;
        }
    }
} catch (Exception $e) {
    $error = $e->getMessage();
}

require 'header.php'; 
?>

<!-- UI STARTS HERE -->
<div class="container-fluid py-4" style="margin-top: 80px;">

    <!-- Alert Messages -->
    <?php if (!empty($_GET['msg'])): ?>
    <div class="alert alert-<?= h($_GET['type'] ?? 'info') ?> alert-dismissible fade show" role="alert">
        <?= h($_GET['msg']) ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    <?php endif; ?>

    <!-- EDIT FORM (Only visible when mode=edit) -->
    <?php if ($mode === 'edit' && $editClient): ?>
    <div class="card mb-4 mx-auto" style="max-width: 600px;">
        <div class="card-header bg-dark text-white">
            <h6 class="mb-0">Update NDA Details: <?= h($editClient['client_name']) ?></h6>
        </div>
        <div class="card-body">
            <form method="post">
                <input type="hidden" name="action" value="update_nda">
                <input type="hidden" name="client_id" value="<?= $editClient['client_id'] ?>">

                <div class="mb-3">
                    <label class="form-label">Client Name</label>
                    <input type="text" class="form-control" value="<?= h($editClient['client_name']) ?>" disabled>
                    <small class="text-muted"><?= !empty($editClient['company_name']) ? h($editClient['company_name']) : '' ?></small>
                </div>

                <div class="row">
                    <div class="col-md-6 mb-3">
                        <label class="form-label">NDA Status (Signed?)</label>
                        <select name="nda_status" class="form-select">
                            <option value="No" <?= (strtolower($editClient['nda_status'] ?? '') == 'no') ? 'selected' : '' ?>>No</option>
                            <option value="Yes" <?= (strtolower($editClient['nda_status'] ?? '') == 'yes') ? 'selected' : '' ?>>Yes</option>
                        </select>
                    </div>

                    <!-- Revision Amount Field -->
                    <div class="col-md-6 mb-3">
                        <label class="form-label">Revision Amount (Extra Clause?)</label>
                        <select name="revision_amount_status" class="form-select">
                            <option value="No" <?= (strtolower($editClient['revision_amount_status'] ?? '') == 'no') ? 'selected' : '' ?>>No</option>
                            <option value="Yes" <?= (strtolower($editClient['revision_amount_status'] ?? '') == 'yes') ? 'selected' : '' ?>>Yes</option>
                        </select>
                        <div class="form-text small">Select 'Yes' if revision fees are applicable.</div>
                    </div>
                </div>

                <div class="mb-3">
                    <label class="form-label">Date of Signing</label>
                    <input type="date" name="signed_date" class="form-control" value="<?= !empty($editClient['signed_date']) && $editClient['signed_date'] != '0000-00-00' ? date('Y-m-d', strtotime($editClient['signed_date'])) : '' ?>">
                </div>

                <div class="d-flex justify-content-end gap-2">
                    <button type="submit" class="btn btn-success">Update Details</button>
                    <a href="nda_details.php" class="btn btn-secondary">Cancel</a>
                </div>
            </form>
        </div>
    </div>
    <?php endif; ?>

    <!-- LIST VIEW -->
    <?php if ($mode === 'view'): ?>
    <div class="d-flex justify-content-between align-items-center mb-4">
        <!-- New Title Here -->
        <h4 class="fw-bold text-primary"><i class="fas fa-file-contract me-2"></i>Client NDA Details</h4>
    </div>

    <!-- Filter Bar -->
    <form method="get" class="row gy-2 gx-2 mb-3 align-items-center">
        <div class="col-auto">
            <input type="text" name="q" value="<?= h($q) ?>" placeholder="Search Client / Company..." class="form-control form-control-sm">
        </div>
        <div class="col-auto">
            <button type="submit" class="btn btn-primary btn-sm">Search</button>
            <a href="nda_details.php" class="btn btn-secondary btn-sm">Clear</a>
        </div>
    </form>

    <?php if (!empty($error)): ?>
    <div class="alert alert-danger">
        Database Error: <?= h($error) ?>
    </div>
    <?php endif; ?>

    <div class="card shadow-sm">
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0 small">
                    <thead class="table-light">
                        <tr>
                            <th>#</th>
                            <th>Client Name</th>
                            <th>Country</th>
                            <th>Client Status</th>
                            <th>NDA Status</th>
                            <th>Revision Amount</th> 
                            <th>Signed Date</th>
                            <th>Signed Year</th>
                            <th class="text-end">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($clients && $clients->num_rows > 0): 
                            $i = 0; 
                            while ($row = $clients->fetch_assoc()): 
                                $i++; 
                        ?>
                        <tr>
                            <td><?= $i ?></td>
                            <td>
                                <strong><?= h($row['client_name']) ?></strong><br>
                                <?php if (!empty($row['company_name'])): ?>
                                <span class="text-muted small"><?= h($row['company_name']) ?></span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?= !empty($row['country']) ? h($row['country']) : '-' ?>
                            </td>
                            <td>
                                <?php if (!empty($row['client_status'])): ?>
                                    <span class="badge bg-<?= $row['client_status'] == 'Active' ? 'success' : ($row['client_status'] == 'Inactive' ? 'danger' : 'secondary') ?>">
                                        <?= h($row['client_status']) ?>
                                    </span>
                                <?php else: ?>
                                    <span class="badge bg-secondary">Unknown</span>
                                <?php endif; ?>
                            </td>
                           
                            <td>
                                <?php if (strtolower($row['nda_status'] ?? '') === 'yes'): ?>
                                    <span class="badge bg-success">Active / Signed</span>
                                <?php else: ?>
                                    <span class="badge bg-secondary">Not Signed</span>
                                <?php endif; ?>
                            </td>
                            
                            <!-- Revision Data -->
                            <td>
                                <?php if (strtolower($row['revision_amount_status'] ?? '') === 'yes'): ?>
                                    <span class="badge bg-warning text-dark">Yes (Applicable)</span>
                                <?php else: ?>
                                    <span class="badge bg-light text-secondary border">No</span>
                                <?php endif; ?>
                            </td>

                            <td>
                                <?= h(formatDate($row['signed_date'])) ?>
                            </td>
                            <td>
                                <?= (!empty($row['signed_date']) && $row['signed_date'] != '0000-00-00') ? date('Y', strtotime($row['signed_date'])) : '-' ?>
                            </td>
                            <td class="text-end">
                                <a href="nda_details.php?mode=edit&id=<?= $row['client_id'] ?>" class="btn btn-sm btn-outline-primary">
                                    Edit NDA
                                </a>
                            </td>
                        </tr>
                        <?php endwhile; else: ?>
                        <tr>
                            <td colspan="9" class="text-center py-4 text-muted">
                                <?= !empty($error) ? "Error loading data" : "No clients found." ?>
                            </td>
                        </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <?php endif; ?>

</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
    // Auto-dismiss alerts after 5 seconds
    document.addEventListener('DOMContentLoaded', function() {
        var alerts = document.querySelectorAll('.alert');
        alerts.forEach(function(alert) {
            setTimeout(function() {
                var bsAlert = new bootstrap.Alert(alert);
                bsAlert.close();
            }, 5000);
        });
    });
</script>
</body>
</html>