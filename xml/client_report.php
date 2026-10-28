<?php
require 'db.php';
if (session_status() === PHP_SESSION_NONE) { 
    session_start(); 
}

// Check if user is logged in and get their username
if (!isset($_SESSION['uid'])) {
    header("Location: login.php");
    exit;
}

// Get current user name from session (adjust based on your session structure)
$current_user = $_SESSION['username'] ?? ''; // Or $_SESSION['user_name'], $_SESSION['name'], etc.


// Get username from request (example: from form, session, or GET/POST)
$employee_username = $current_user; // Replace with actual username source

// Prepare SQL query to fetch employee_name
$sql = "SELECT `employee_name` FROM `employee_master` WHERE `employee_username` = ?";

$stmt = $mysqli->prepare($sql);
$stmt->bind_param("s", $employee_username);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows > 0) {
    // Fetch the employee name
    $row = $result->fetch_assoc();
    $current_user_name = $row['employee_name'];

} 
if (empty($current_user_name)) {
    die("User information not found. Please login again.");
}

// Helper functions
function h($s) { 
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); 
}

function formatDate($d, $format = 'd-m-Y') {
    if (empty($d) || $d === '0000-00-00' || $d === '0000-00-00 00:00:00') return '—';
    return date($format, strtotime($d));
}

function getProcessName($process_id, $mysqli) {
    if (!$process_id) return '—';
    $stmt = $mysqli->prepare("SELECT job_name FROM job_master WHERE job_id = ?");
    $stmt->bind_param("i", $process_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();
    $stmt->close();
    return $row ? $row['job_name'] : '—';
}

// --- START OF IMPORT LOGIC ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'import_excel') {
    if (isset($_FILES['excel_file']) && $_FILES['excel_file']['error'] === 0) {
        $libPath = dirname(__DIR__) . '/simplexlsx-master/src/SimpleXLSX.php';
        if (file_exists($libPath)) {
            require_once $libPath;
            $xlsx = Shuchkin\SimpleXLSX::parse($_FILES['excel_file']['tmp_name']);
            if ($xlsx) {
                $rows = $xlsx->rows();
                $count = 0;
                foreach ($rows as $k => $r) {
                    if ($k === 0) continue; // Skip header row
                    
                    // Skip completely empty rows
                    if (empty(array_filter($r))) continue;

                    // 1. Date (Column A / 0)
                    $raw_date = trim($r[0] ?? '');
                    if (!empty($raw_date)) {
                        $err_date = date('Y-m-d', strtotime(str_replace('/', '-', $raw_date)));
                    } else {
                        $err_date = date('Y-m-d');
                    }

                    // 2. Journal name (Column B / 1)
                    $client_name = trim($r[1] ?? '');

                    // 3. Pubcode (Column C / 2 - DB auto-increments id, so skipped)

                    // 4. Sr. PE (Column D / 3)
                    $sr_pe = trim($r[3] ?? '');
                    if (empty($sr_pe)) {
                        $sr_pe = $current_user_name; // Default to logged-in user
                    }

                    // 5. PE (Column E / 4)
                    $pe = trim($r[4] ?? '');

                    // 6. Vendor (Column F / 5 - Tapasi)

                    // 7. Type of concern (Column G / 6)
                    $err_type = trim($r[6] ?? '');

                    // 8. Short description of concern (Column H / 7)
                    $article = trim($r[7] ?? '');
                    // Truncate to prevent MySQL 'Data too long' error if column is small (e.g., 255 chars)
                    $article = substr($article, 0, 255);

                    // 9. Is the quality error repeated? (Column I / 8)
                    $yn = trim($r[8] ?? 'No');

                    // 10. Vendor Comments (Column J / 9)
                    $comment = trim($r[9] ?? '');

                    // 11. Error accepted by Vendor? (Column K / 10)

                    // 12. Root Cause Analysis (Column L / 11)
                    $reason = trim($r[11] ?? $comment);

                    $stmt = $mysqli->prepare("INSERT INTO error_log (error_date, client_name, error_made_by, error_taken_by, error_type, article, yn, comment, reason, error_photo, status, created_date) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, '', 'Active', NOW())");
                    if ($stmt) {
                        $stmt->bind_param("sssssssss", $err_date, $client_name, $sr_pe, $pe, $err_type, $article, $yn, $comment, $reason);
                        if ($stmt->execute()) {
                            $count++;
                        }
                        $stmt->close();
                    }
                }
                header("Location: client_report.php?msg=" . urlencode("$count Records Imported Successfully") . "&type=success");
                exit;
            } else {
                header("Location: client_report.php?msg=" . urlencode("Excel Parse Error: " . Shuchkin\SimpleXLSX::parseError()) . "&type=danger");
                exit;
            }
        }
    }
}

// Handle Excel Export (Clean plain headers without grey background)
if (isset($_GET['export']) && $_GET['export'] === 'excel') {
    while (ob_get_level()) {
        ob_end_clean();
    }

    header('Content-Type: application/vnd.ms-excel; charset=utf-8');
    header('Content-Disposition: attachment; filename="client_report_' . date('Y-m-d') . '.xls"');
    header('Pragma: no-cache');
    header('Expires: 0');
    
    // Get filters from URL
    $from_date = $_GET['from_date'] ?? '';
    $to_date = $_GET['to_date'] ?? '';
    $q = trim($_GET['q'] ?? '');
    
    // Build WHERE conditions - SHOW ALL ACTIVE RECORDS
$where = ["el.status = ?"];
$params = ['Active'];
$types = 's';
    
    if (!empty($from_date)) {
        $where[] = "el.error_date >= ?";
        $params[] = $from_date;
        $types .= 's';
    }
    
    if (!empty($to_date)) {
        $where[] = "el.error_date <= ?";
        $params[] = $to_date;
        $types .= 's';
    }
    
    if ($q !== '') {
        $where[] = "(el.article LIKE ? OR el.client_name LIKE ? OR el.error_type LIKE ?)";
        $params = array_merge($params, ["%$q%", "%$q%", "%$q%"]);
        $types .= 'sss';
    }
    
    $where_sql = "WHERE " . implode(" AND ", $where);
    
    $export_sql = "SELECT * FROM error_log el $where_sql ORDER BY el.error_date DESC";
    $stmt = $mysqli->prepare($export_sql);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $results = $stmt->get_result();
    
    echo "<table border='1'>";
    echo "<tr>";
    echo "<th>Date</th>";
    echo "<th>Journal name</th>";
    echo "<th>Pubcode</th>";
    echo "<th>Sr. PE</th>";
    echo "<th>PE</th>";
    echo "<th>Vendor</th>";
    echo "<th>Type of concern</th>";
    echo "<th>Short description of concern</th>";
    echo "<th>Is the quality error repeated? (Yes/No)</th>";
    echo "<th>Vendor Comments</th>";
    echo "<th>Error accepted by Vendor? (Yes/No)</th>";
    echo "<th>Root Cause Analysis</th>";
    echo "<th>Corrective And Preventive Action</th>";
    echo "</tr>";
    
    if ($results && $results->num_rows > 0) {
        while ($row = $results->fetch_assoc()) {
            echo "<tr>";
            echo "<td>" . formatDate($row['error_date']) . "</td>";
            echo "<td>" . h($row['client_name']) . "</td>";
            echo "<td>" . h($row['id']) . "</td>";
            echo "<td>" . h($row['error_made_by']) . "</td>";
            echo "<td>" . h($row['error_taken_by']) . "</td>";
            echo "<td>Tapasi</td>";
            echo "<td>" . h($row['error_type']) . "</td>";
            echo "<td>" . h($row['article']) . "</td>";
            echo "<td>" . h($row['yn'] ?? 'No') . "</td>";
            echo "<td>" . h($row['comment'] ?? $row['reason']) . "</td>";
            echo "<td>Yes</td>";
            echo "<td>" . h($row['reason']) . "</td>";
            echo "<td>—</td>";
            echo "</tr>";
        }
    } else {
        echo "<tr><td colspan='13' align='center'>No records found</td></tr>";
    }
    
    echo "</table>";
    exit;
}

// Get filters for page display
$from_date = $_GET['from_date'] ?? '';
$to_date = $_GET['to_date'] ?? '';
$q = trim($_GET['q'] ?? '');

// Build WHERE conditions - SHOW ALL ACTIVE RECORDS
$where = ["el.status = ?"];
$params = ['Active'];
$types = 's';

if ($from_date) {
    $where[] = "el.error_date >= ?";
    $params[] = $from_date;
    $types .= 's';
}

if ($to_date) {
    $where[] = "el.error_date <= ?";
    $params[] = $to_date;
    $types .= 's';
}

if ($q !== '') {
    $where[] = "(el.article LIKE ? OR el.client_name LIKE ? OR el.error_type LIKE ?)";
    $params = array_merge($params, ["%$q%", "%$q%", "%$q%"]);
    $types .= 'sss';
}

$where_sql = "WHERE " . implode(" AND ", $where);

// Main query - fetching from error_log
$sql = "SELECT * FROM error_log el $where_sql ORDER BY el.error_date DESC LIMIT 1000";
$stmt = $mysqli->prepare($sql);
$stmt->bind_param($types, ...$params);
$stmt->execute();
$articles = $stmt->get_result();

// Get total count for display
$count_sql = "SELECT COUNT(*) as total FROM error_log el $where_sql";
$count_stmt = $mysqli->prepare($count_sql);
$count_stmt->bind_param($types, ...$params);
$count_stmt->execute();
$total_count = $count_stmt->get_result()->fetch_assoc()['total'] ?? 0;
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Jobs - <?= h($current_user_name) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/dataTables.bootstrap5.min.css">
    <style>
        body {
            background-color: #f8f9fa;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }
        .main-content {
            padding: 20px;
            max-width: 1400px;
            margin: 0 auto;
        }
        .table th {
            background-color: #5e6b77 !important;
            color: #ffffff !important; 
            border: 1px solid #dee2e6 !important; /* Vertical lines for header */
            text-align: center;
        }
        .table td {
            vertical-align: middle;
            border: 1px solid #dee2e6; /* Vertical lines for data cells */
        }
        
        .card {
            box-shadow: 0 0.125rem 0.25rem rgba(0, 0, 0, 0.075);
            border: 1px solid rgba(0, 0, 0, 0.125);
        }
        .user-header {
            background: #6c757d;
            color: white;
            border-radius: 8px;
            padding: 20px;
            margin-bottom: 20px;
        }
        .filter-card {
            background-color: white;
        }
        .export-btn {
            background: linear-gradient(135deg, #11998e 0%, #38ef7d 100%);
            color: white;
            border: none;
        }
        .export-btn:hover {
            background: linear-gradient(135deg, #0d7a71 0%, #2dc066 100%);
            color: white;
        }
        .user-badge {
            background-color: rgba(255, 255, 255, 0.2);
            padding: 5px 15px;
            border-radius: 20px;
            font-size: 0.9em;
        }
    </style>
</head>
<body>
    <?php require 'header.php'; ?>
    
    <div class="main-content">
        <!-- User Header -->
        <div class="user-header">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <!-- <h4><i class="fas fa-tasks me-2"></i>My Jobs</h4> -->
                    <div class="mt-2">
                        <span class="user-badge">User: <?= h($current_user_name) ?></span>
                        <!-- <span class="user-badge ms-2">Total Jobs: <?= $total_count ?></span> -->
                    </div>
                </div>
                <div class="d-flex gap-2">
                <!-- Import Button - Now same Green as Export -->
                <button type="button" class="btn export-btn fw-bold" data-bs-toggle="modal" data-bs-target="#importModal">
                    <i class="fas fa-file-upload me-1"></i> Import Excel
                </button>
                
                <?php if ($total_count > 0): ?>
                <!-- Export Button -->
                <a href="?export=excel&from_date=<?= h($from_date) ?>&to_date=<?= h($to_date) ?>&q=<?= h($q) ?>" 
                class="btn export-btn fw-bold" id="btnExportExcel">
                    <i class="fas fa-file-excel me-1"></i> Export to Excel
                </a>
                <?php endif; ?>
            </div>
            </div>
        </div>

        <!-- Filter Card -->
        <!-- <div class="card filter-card mb-4">
            <div class="card-body">
                <form method="get" class="row g-3 align-items-center">
                    <div class="col-md-2">
                        <label class="form-label mb-1"><i class="fas fa-calendar me-1"></i> From Date</label>
                        <input type="date" name="from_date" class="form-control form-control-sm" value="<?= h($from_date) ?>">
                    </div>
                    
                    <div class="col-md-2">
                        <label class="form-label mb-1"><i class="fas fa-calendar me-1"></i> To Date</label>
                        <input type="date" name="to_date" class="form-control form-control-sm" value="<?= h($to_date) ?>">
                    </div>
                    
                    <div class="col-md-3">
                        <label class="form-label mb-1"><i class="fas fa-cogs me-1"></i> Process</label>
                        <select name="process_id" class="form-select form-select-sm">
                            <option value="0">All Processes</option>
                            <?php foreach ($processes as $process): ?>
                                <option value="<?= $process['job_id'] ?>" <?= $process_id == $process['job_id'] ? 'selected' : '' ?>>
                                    <?= h($process['job_name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="col-md-3">
                        <label class="form-label mb-1"><i class="fas fa-search me-1"></i> Search</label>
                        <input type="text" name="q" class="form-control form-control-sm" 
                               placeholder="Search issue or article..." value="<?= h($q) ?>">
                    </div>
                    
                    <div class="col-md-2">
                        <label class="form-label mb-1">&nbsp;</label>
                        <div class="d-flex">
                            <button type="submit" class="btn btn-primary btn-sm me-2">
                                <i class="fas fa-filter me-1"></i> Filter
                            </button>
                            <a href="my_jobs.php" class="btn btn-outline-secondary btn-sm">
                                <i class="fas fa-times me-1"></i> Clear
                            </a>
                        </div>
                    </div>
                    
                    <div class="col-md-12 mt-3">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <span class="text-muted">
                                    <i class="fas fa-chart-bar me-1"></i> Showing: <strong><?= $articles->num_rows ?></strong> jobs
                                </span>
                            </div>
                            <div>
                                <small class="text-muted">
                                    <i class="fas fa-info-circle me-1"></i>
                                    Showing only jobs assigned to you
                                </small>
                            </div>
                        </div>
                    </div>
                </form>
            </div>
        </div> -->

        <!-- Jobs Table -->
        <div class="card">
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0" id="jobsTable">
                        <thead class="table-light">
                            <tr>
                                <th>Date</th>
                                <th>Journal name</th>
                                <th>Pubcode (First enter here)</th>
                                <th>Sr. PE</th>
                                <th>PE</th>
                                <th>Vendor</th>
                                <th>Type of concern</th>
                                <th>Short description of concern (In case of TAT, please mention received and sent dates)</th>
                                <th>Is the quality error repeated? (Yes/No)</th>
                                <th>Vendor Comments</th>
                                <th>Error accepted by Vendor? (Yes/No)</th>
                                <th>Root Cause Analysis</th>
                                <th>Corrective And Preventive Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if($articles && $articles->num_rows > 0): ?>
                                <?php while($row = $articles->fetch_assoc()): ?>
                                <tr>
                                    <td><?= formatDate($row['error_date']) ?></td> <!-- Date -->
                                    <td><?= h($row['client_name']) ?></td> <!-- Journal Name -->
                                    <td class="fw-bold"><?= h($row['id']) ?></td> <!-- Pubcode -->
                                    <td><?= h($row['error_made_by']) ?></td> <!-- Sr. PE -->
                                    <td><?= h($row['error_taken_by']) ?></td> <!-- PE -->
                                    <td>Tapasi</td> <!-- Vendor -->
                                    <td><?= h($row['error_type']) ?></td> <!-- Type of concern -->
                                    <td><?= h($row['article']) ?></td> <!-- Short description -->
                                    <td><?= h($row['yn'] ?? 'No') ?></td> <!-- Repeated? -->
                                    <td><?= h($row['reason']) ?></td> <!-- Vendor Comments -->
                                    <td>Yes</td> <!-- Error accepted -->
                                    <td><?= h($row['reason']) ?></td> <!-- Root Cause -->
                                    <td>—</td> <!-- Action -->
                                </tr>
                                <?php endwhile; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <!-- START OF IMPORT MODAL -->
    <div class="modal fade" id="importModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header" style="background-color: #5e6b77; color: white;">
                    <h5 class="modal-title fw-bold"><i class="fas fa-file-excel me-2"></i>Import Error Reports</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form action="" method="post" enctype="multipart/form-data">
                    <input type="hidden" name="action" value="import_excel">
                    <div class="modal-body">
                        <div class="mb-3">
                            <label class="form-label fw-bold">Select Excel File (.xlsx)</label>
                            <input type="file" name="excel_file" class="form-control" accept=".xlsx" required>
                        </div>
                        <div class="alert alert-info small py-2">
                            <i class="fas fa-info-circle me-1"></i> <strong>Excel Columns Order:</strong><br>
                            Date | Journal name | Pubcode | Sr. PE | PE | Vendor | Type of concern | Short description | Repeated? (Yes/No) | Vendor Comments | Error accepted | Root Cause Analysis | Action
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary fw-bold">Upload & Import</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- DataTables Scripts -->
    <script src="https://code.jquery.com/jquery-3.7.0.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.6/js/dataTables.bootstrap5.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    
    <script>
        $(document).ready(function() {
            $('#jobsTable').DataTable({
                "pageLength": 25,
                "order": [[0, 'desc']],
                "language": {
                    "emptyTable": "No error reports assigned to you yet.",
                    "search": "Search within results:"
                }
            });
        });
    </script>
</body>
</html>
