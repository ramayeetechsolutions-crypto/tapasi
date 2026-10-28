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

// Handle Excel Export for current user only
if (isset($_GET['export']) && $_GET['export'] === 'excel') {
    header('Content-Type: application/vnd.ms-excel');
    header('Content-Disposition: attachment; filename="my_jobs_' . date('Y-m-d') . '.xls"');
    
    // Get filters from URL
    $from_date = $_GET['from_date'] ?? '';
    $to_date = $_GET['to_date'] ?? '';
    $process_id = (int)($_GET['process_id'] ?? 0);
    $q = trim($_GET['q'] ?? '');
    
    // Build WHERE conditions for export - FILTER BY CURRENT USER
    $where = ["j.assigned_to = ?"];
    $params = [$current_user_name];
    $types = 's';
    
    if ($from_date) {
        $where[] = "j.job_date >= ?";
        $params[] = $from_date;
        $types .= 's';
    }
    
    if ($to_date) {
        $where[] = "j.job_date <= ?";
        $params[] = $to_date;
        $types .= 's';
    }
    
    if ($process_id > 0) {
        $where[] = "j.process_id = ?";
        $params[] = $process_id;
        $types .= 'i';
    }
    
    if ($q !== '') {
        $where[] = "(j.journal_issue LIKE ? OR j.article LIKE ?)";
        $params = array_merge($params, ["%$q%", "%$q%"]);
        $types .= 'ss';
    }
    
    $where_sql = "WHERE " . implode(" AND ", $where);
    
    // Export query - filter by assigned_to
    $export_sql = "SELECT 
                    j.job_id,
                    j.job_date,
                    j.journal_issue,
                    j.article,
                    jm.job_name as process,
                    j.assigned_to,
                    j.due_date,
                    j.dispatch_date,
                    j.file_path
                FROM jobs j
                JOIN job_master jm ON j.process_id = jm.job_id 
                $where_sql
                ORDER BY j.job_date DESC";
    
    $stmt = $mysqli->prepare($export_sql);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $results = $stmt->get_result();
    
    echo "<table border='1'>";
    echo "<tr>";
    echo "<th>ID</th>";
    echo "<th>Date</th>";
    echo "<th>Journal Issue</th>";
    echo "<th>Article</th>";
    echo "<th>Process</th>";
    echo "<th>Assigned To</th>";
    echo "<th>Due Date</th>";
    echo "<th>Dispatch Date</th>";
    echo "<th>File Path</th>";
    echo "</tr>";
    
    if ($results && $results->num_rows > 0) {
        while ($row = $results->fetch_assoc()) {
            echo "<tr>";
            echo "<td>" . h($row['job_id']) . "</td>";
            echo "<td>" . formatDate($row['job_date']) . "</td>";
            echo "<td>" . h($row['journal_issue']) . "</td>";
            echo "<td>" . h($row['article']) . "</td>";
            echo "<td>" . h($row['process']) . "</td>";
            echo "<td>" . h($row['assigned_to']) . "</td>";
            echo "<td>" . formatDate($row['due_date']) . "</td>";
            echo "<td>" . formatDate($row['dispatch_date']) . "</td>";
            echo "<td>" . h($row['file_path']) . "</td>";
            echo "</tr>";
        }
    } else {
        echo "<tr><td colspan='9' align='center'>No jobs found</td></tr>";
    }
    
    echo "</table>";
    exit;
}

// Get filters
$from_date = $_GET['from_date'] ?? '';
$to_date = $_GET['to_date'] ?? '';
$process_id = (int)($_GET['process_id'] ?? 0);
$q = trim($_GET['q'] ?? '');

// Get all processes for dropdown
$processes_result = $mysqli->query("SELECT job_id, job_name FROM job_master WHERE status = 'Active' ORDER BY job_name");
$processes = [];
if ($processes_result) {
    while ($row = $processes_result->fetch_assoc()) {
        $processes[] = $row;
    }
}

// Build WHERE conditions - FILTER BY CURRENT USER
$where = ["j.assigned_to = ?"];
$params = [$current_user_name];
$types = 's';

if ($from_date) {
    $where[] = "j.job_date >= ?";
    $params[] = $from_date;
    $types .= 's';
}

if ($to_date) {
    $where[] = "j.job_date <= ?";
    $params[] = $to_date;
    $types .= 's';
}

if ($process_id > 0) {
    $where[] = "j.process_id = ?";
    $params[] = $process_id;
    $types .= 'i';
}

if ($q !== '') {
    $where[] = "(j.journal_issue LIKE ? OR j.article LIKE ?)";
    $params = array_merge($params, ["%$q%", "%$q%"]);
    $types .= 'ss';
}

$where_sql = "WHERE " . implode(" AND ", $where);

// Main query - only for current user
$sql = "SELECT 
            j.job_id,
            j.job_date,
            j.journal_issue,
            j.article,
            j.process_id,
            j.assigned_to,
            j.due_date,
            j.dispatch_date,
            j.file_path
        FROM jobs j
        $where_sql
        ORDER BY j.job_date DESC
        LIMIT 1000";

// Execute query with safety check
$stmt = $mysqli->prepare($sql);
if (!$stmt) {
    die("Database Query Preparation Failed: " . $mysqli->error . "<br>SQL: " . $sql);
}

$stmt->bind_param($types, ...$params);
$stmt->execute();
$articles = $stmt->get_result();

// Get total count for display with safety check
$count_sql = "SELECT COUNT(*) as total FROM jobs j $where_sql";
$count_stmt = $mysqli->prepare($count_sql);
if (!$count_stmt) {
    die("Database Count Preparation Failed: " . $mysqli->error);
}

$count_stmt->bind_param($types, ...$params);
$count_stmt->execute();
$count_result = $count_stmt->get_result();
$total_count = $count_result->fetch_assoc()['total'] ?? 0;
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
            /* background-color: #2c3e50; */
             background-color: #5e6b77 !important;
            color: white;
            border: none;
        }
        .table td {
            vertical-align: middle;
        }
        .card {
            box-shadow: 0 0.125rem 0.25rem rgba(0, 0, 0, 0.075);
            border: 1px solid rgba(0, 0, 0, 0.125);
        }
        .user-header {
            /* background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); */
            background: #5e6b77 !important; 
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
                    <h4><i class="fas fa-tasks me-2"></i>My Jobs</h4>
                    <div class="mt-2">
                        <span class="user-badge">User: <?= h($current_user_name) ?></span>
                        <span class="user-badge ms-2">Total Jobs: <?= $total_count ?></span>
                    </div>
                </div>
                <div>
                    <?php if ($total_count > 0): ?>
                    <a href="?export=excel&from_date=<?= h($from_date) ?>&to_date=<?= h($to_date) ?>&process_id=<?= $process_id ?>&q=<?= h($q) ?>" 
                       class="btn export-btn">
                        <i class="fas fa-file-excel me-1"></i> Export to Excel
                    </a>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Filter Card -->
        <div class="card filter-card mb-4">
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
        </div>

        <!-- Jobs Table -->
        <div class="card">
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0" id="jobsTable">
                        <thead class="table-light">
                            <tr>
                                <th width="60">ID</th>
                                <th width="100">Date</th>
                                <th>Journal Issue</th>
                                <th>Article</th>
                                <th width="100">Process</th>
                                <th width="120">Assigned To</th>
                                <th width="100">Due Date</th>
                                <th width="100">Dispatch Date</th>
                                <th width="200">File Path</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if($articles && $articles->num_rows > 0): ?>
                                <?php while($row = $articles->fetch_assoc()): 
                                    $process_name = getProcessName($row['process_id'], $mysqli);
                                ?>
                                <tr>
                                    <td class="fw-bold"><?= (int)$row['job_id'] ?></td>
                                    <td>
                                        <small class="text-muted"><?= formatDate($row['job_date']) ?></small>
                                    </td>
                                    <td>
                                        <?= h($row['journal_issue']) ?>
                                    </td>
                                    <td>
                                        <?= h($row['article']) ?>
                                    </td>
                                    <td>
                                        <span class="badge bg-info"><?= h($process_name) ?></span>
                                    </td>
                                    <td>
                                        <span class="badge bg-primary"><?= h($row['assigned_to'] ?: '—') ?></span>
                                    </td>
                                    <td>
                                        <small class="text-muted"><?= formatDate($row['due_date']) ?></small>
                                    </td>
                                    <td>
                                        <?php if ($row['dispatch_date'] && $row['dispatch_date'] != '0000-00-00' && $row['dispatch_date'] != '0000-00-00 00:00:00'): ?>
                                            <span class="badge bg-success"><?= formatDate($row['dispatch_date']) ?></span>
                                        <?php else: ?>
                                            <span class="badge bg-secondary">Pending</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($row['file_path']): ?>
                                            <small class="text-truncate d-inline-block" style="max-width: 180px;" 
                                                   title="<?= h($row['file_path']) ?>">
                                                <?= h(basename($row['file_path'])) ?>
                                            </small>
                                        <?php else: ?>
                                            <small class="text-muted">—</small>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <?php endwhile; ?>
                            <?php else: ?>
                                <tr>
                                     <td class="text-center py-5">
                                    <td class="text-center py-5">
                                    <td class="text-center py-5">
                                    <td class="text-center py-5">
                                   
                                    <td class="text-center py-5">
                                        <div class="text-muted">
                                            <i class="fas fa-file-alt fa-3x mb-3"></i>
                                            <h5>No jobs found</h5>
                                            <p class="mb-0">You don't have any jobs assigned to you yet.</p>
                                        </div>
                                    </td> <td class="text-center py-5">
                                    <td class="text-center py-5">
                                    <td class="text-center py-5">
                                    <td class="text-center py-5">
                                   
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
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
            // Initialize DataTable only if there are records
            if ($('#jobsTable tbody tr:first').length > 0) {
                $('#jobsTable').DataTable({
                    "pageLength": 25,
                    "order": [[0, 'desc']],
                    "language": {
                        "search": "Search within results:",
                        "lengthMenu": "Show _MENU_ entries",
                        "info": "Showing _START_ to _END_ of _TOTAL_ entries",
                        "paginate": {
                            "previous": "← Previous",
                            "next": "Next →"
                        }
                    }
                });
            }
            
            // Auto-submit form on process change
            $('select[name="process_id"]').change(function() {
                $(this).closest('form').submit();
            });
            
            // Add tooltips
            $('[title]').tooltip({
                placement: 'top'
            });
            
            // Export button confirmation
            $('.export-btn').click(function(e) {
                if ($('#jobsTable tbody tr:first').length === 0) {
                    e.preventDefault();
                    alert('No data to export!');
                }
            });
        });
    </script>
</body>
</html>