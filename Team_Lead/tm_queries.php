<br><br><br>
<?php
require 'db.php';
session_start();
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Get current user from session (make sure you set this during login)
$current_username = $_SESSION['username'] ?? '';
$employee_username = $current_username;

// Prepare SQL query to fetch employee_name
$sql = "SELECT `employee_name` FROM `employee_master` WHERE `employee_username` = ?";
$stmt = $mysqli->prepare($sql);
$stmt->bind_param("s", $employee_username);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows > 0) {
    $row = $result->fetch_assoc();
    $current_name = $row['employee_name'];
} else {
    $current_name = 'Unknown';
}

// Helper functions
function h($s) { 
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); 
}

function formatDate($d) {
    if (empty($d) || $d === '0000-00-00' || $d === '0000-00-00 00:00:00') return '—';
    return date('d-m-Y', strtotime($d));
}

function redirect_with($params = []) {
    $base = 'tm_queries.php';
    if (!empty($params)) $base .= '?' . http_build_query($params);
    header("Location: $base");
    exit;
}

// Handle POST action for adding queries only
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'add') {
        // Get data from form
        $employee_username = trim($_POST['employee_username'] ?? '');
        $name = trim($_POST['name'] ?? '');
        $query_date = $_POST['query_date'] ?? '';
        $query = trim($_POST['query'] ?? '');
        $client = trim($_POST['client'] ?? '');
        $issue = trim($_POST['issue'] ?? '');
        $article = trim($_POST['article'] ?? '');
        
        // Basic validation
        $errors = [];
        if ($employee_username === '') $errors[] = 'Employee Username is required';
        if ($name === '') $errors[] = 'Name is required';
        if ($query_date === '') $errors[] = 'Date is required';
        if ($query === '') $errors[] = 'Query is required';
        
        if (!empty($errors)) {
            redirect_with(['msg' => implode(', ', $errors), 'type' => 'danger']);
        }
        
        // Validate date format
        if (!strtotime($query_date)) {
            redirect_with(['msg' => 'Invalid date format', 'type' => 'danger']);
        }
        
        // Check if employee exists in employee_master
        $check_stmt = $mysqli->prepare("SELECT employee_id FROM employee_master WHERE employee_username = ? AND status = 'Active'");
        $check_stmt->bind_param("s", $employee_username);
        $check_stmt->execute();
        $check_stmt->store_result();
        
        if ($check_stmt->num_rows === 0) {
            $check_stmt->close();
            redirect_with(['msg' => 'Employee not found or inactive', 'type' => 'danger']);
        }
        $check_stmt->close();
        
        // Insert new query (Puthu columns add panniyachu)
        $stmt = $mysqli->prepare("INSERT INTO queries (employee_username, name, query_date, query, client, issue, article, status) VALUES (?, ?, ?, ?, ?, ?, ?, 'pending')");
        $stmt->bind_param("sssssss", $employee_username, $name, $query_date, $query, $client, $issue, $article);
        
        if ($stmt->execute()) {
            redirect_with(['msg' => "Query submitted successfully", 'type' => 'success']);
        } else {
            redirect_with(['msg' => 'Error submitting query: ' . $stmt->error, 'type' => 'danger']);
        }
        $stmt->close();
    }
    
    redirect_with();
}

// Get filter parameters
$date_from = $_GET['date_from'] ?? '';
$date_to = $_GET['date_to'] ?? '';

// Build query to show only current user's queries (Added client, issue, article)
$sql = "SELECT q.id, q.employee_username, q.name, q.query_date, q.query, q.status, 
               q.admin_response, q.response_date, q.created_at,
               q.client, q.issue, q.article
        FROM queries q
        WHERE q.employee_username = ?";
        
$params = [];
$types = 's';
$params[] = $current_username;

// Add date filters
if ($date_from !== '') {
    $sql .= " AND q.query_date >= ?";
    $params[] = $date_from;
    $types .= 's';
}

if ($date_to !== '') {
    $sql .= " AND q.query_date <= ?";
    $params[] = $date_to;
    $types .= 's';
}

$sql .= " ORDER BY q.query_date DESC";

// Execute query
$stmt = $mysqli->prepare($sql);
$stmt->bind_param($types, ...$params);
$stmt->execute();
$queries = $stmt->get_result();

// If query fails, show empty result
if (!$queries) {
    $queries = false;
}
?>
<?php require 'header.php';?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Query Management System</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body {
            background-color: #f8f9fa;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            margin: 0;
            padding: 0;
        }
        .main-content {
            padding: 20px;
            max-width: 100%;
            margin: 0;
        }
        .container-fluid {
            padding: 0;
        }
        .table th {
            /* background-color: #2c3e50; */
            background-color: #707981 !important; 
            color: white;
            border: none;
        }
        .table td {
            vertical-align: middle;
        }
        .card {
            box-shadow: 0 0.125rem 0.25rem rgba(0, 0, 0, 0.075);
            border: 1px solid rgba(0, 0, 0, 0.125);
            margin-bottom: 20px;
            height: 100%;
        }
        .btn-group-sm > .btn {
            padding: 0.25rem 0.5rem;
        }
        .query-text {
            max-height: 100px;
            overflow: hidden;
            text-overflow: ellipsis;
            display: -webkit-box;
            -webkit-line-clamp: 3;
            -webkit-box-orient: vertical;
        }
        .status-badge {
            font-size: 0.75rem;
            padding: 0.25rem 0.5rem;
            border-radius: 0.25rem;
        }
        .status-pending { background-color: #ffc107; color: #000; }
        .status-in_progress { background-color: #0dcaf0; color: #000; }
        .status-resolved { background-color: #198754; color: #fff; }
        .user-info {
            /* background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); */
             background-color: #707981 !important;
            color: white;
            padding: 10px 15px;
            border-radius: 5px;
            margin-bottom: 15px;
        }
        .btn-submit {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            border: none;
            color: white;
            width: 100%;
            padding: 10px;
            font-size: 16px;
            font-weight: 600;
        }
        .btn-submit:hover {
            background: linear-gradient(135deg, #764ba2 0%, #667eea 100%);
            color: white;
        }
        .form-section {
            background: white;
            border-radius: 8px;
            padding: 25px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.1);
        }
        .table-section {
            background: white;
            border-radius: 8px;
            padding: 25px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.1);
            height: calc(100vh - 200px);
            overflow-y: auto;
        }
        .section-title {
            color: #2c3e50;
            border-bottom: 2px solid #667eea;
            padding-bottom: 10px;
            margin-bottom: 20px;
            font-weight: 600;
        }
        .form-control:focus {
            border-color: #667eea;
            box-shadow: 0 0 0 0.2rem rgba(102, 126, 234, 0.25);
        }
        .filter-card {
            background: #f8f9fa;
            border: 1px solid #dee2e6;
            border-radius: 8px;
            padding: 15px;
            margin-bottom: 20px;
        }
        .info-box {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 20px;
            text-align: center;
        }
        .info-box h6 {
            margin: 0;
            font-weight: 600;
        }
        .info-box p {
            margin: 5px 0 0 0;
            font-size: 14px;
            opacity: 0.9;
        }
        @media (max-width: 768px) {
            .table-section {
                height: auto;
                margin-top: 20px;
            }
        }
    </style>
</head>
<body>
    <div class="main-content">
        <!-- User Info Panel -->
        <div class="user-info d-flex justify-content-between align-items-center">
            <div>
                <h5 class="mb-0"><i class="fas fa-user-circle me-2"></i>Welcome, <?= h($current_name) ?>!</h5>
                <small><i class="fas fa-id-card me-1"></i><?= h($current_username) ?> | Employee Query Portal</small>
            </div>
        </div>
        
        <?php if (!empty($_GET['msg'])): ?>
        <div class="alert alert-<?= h($_GET['type'] ?? 'info') ?> alert-dismissible fade show" role="alert">
            <i class="fas fa-info-circle me-2"></i><?= h($_GET['msg']) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <?php endif; ?>

        <div class="container-fluid">
            <div class="row">
                <!-- Left Column: Query Entry Form -->
                <div class="col-lg-5 col-md-12">
                    <div class="form-section">
                        <h4 class="section-title"><i class="fas fa-edit me-2"></i>Submit New Query</h4>
                        
                        <!-- User Info Boxes -->
                        <div class="row mb-4">
                            <div class="col-md-6">
                                <div class="info-box">
                                    <h6><i class="fas fa-user me-2"></i>Username</h6>
                                    <p><?= h($current_username) ?></p>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="info-box">
                                    <h6><i class="fas fa-id-badge me-2"></i>Name</h6>
                                    <p><?= h($current_name) ?></p>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Query Form -->
                        <form method="post" autocomplete="off">
                            <input type="hidden" name="action" value="add">
                            <input type="hidden" name="employee_username" value="<?= h($current_username) ?>">
                            <input type="hidden" name="name" value="<?= h($current_name) ?>">

                            <div class="mb-3">
                                <label for="query_date" class="form-label">
                                    <i class="fas fa-calendar-alt me-2"></i>Query Date <span class="text-danger">*</span>
                                </label>
                                <input type="date" class="form-control" id="query_date" name="query_date" 
                                       value="<?= date('Y-m-d') ?>" required max="<?= date('Y-m-d') ?>">
                            </div>

                            <!-- Puthusa Add panna Client, Issue, Article Inputs -->
                            <div class="row">
                                <div class="col-md-4 mb-3">
                                    <label class="form-label"><i class="fas fa-building me-1"></i>Client</label>
                                    <input type="text" class="form-control" name="client" placeholder="Enter Client">
                                </div>
                                <div class="col-md-4 mb-3">
                                    <label class="form-label"><i class="fas fa-book-open me-1"></i>Issue</label>
                                    <input type="text" class="form-control" name="issue" placeholder="Enter Issue">
                                </div>
                                <div class="col-md-4 mb-3">
                                    <label class="form-label"><i class="fas fa-file-alt me-1"></i>Article</label>
                                    <input type="text" class="form-control" name="article" placeholder="Enter Article">
                                </div>
                            </div>

                            <div class="mb-3">
                                <label for="query" class="form-label">
                                    <i class="fas fa-comment-dots me-2"></i>Query Details <span class="text-danger">*</span>
                                </label>
                                <textarea class="form-control" id="query" name="query" rows="8" 
                                          placeholder="Please describe your query in detail. Be specific about your issue or question for better assistance..." 
                                          required></textarea>
                                <div class="form-text mt-2">
                                    <i class="fas fa-lightbulb me-1"></i> Tips: Include specific details, error messages, and what you've tried.
                                </div>
                            </div>

                            <div class="d-grid gap-2 mt-4">
                                <button type="submit" class="btn btn-submit">
                                    <i class="fas fa-paper-plane me-2"></i> Submit Query
                                </button>
                            </div>
                        </form>
                    </div>
                </div>

                <!-- Right Column: Data Table -->
                <div class="col-lg-7 col-md-12">
                    <div class="table-section">
                        <div class="d-flex justify-content-between align-items-center mb-4">
                            <h4 class="section-title mb-0"><i class="fas fa-list me-2"></i>My Query History</h4>
                            <span class="badge bg-primary">
                                <?php if($queries): ?>
                                    <?= $queries->num_rows ?> query(ies)
                                <?php else: ?>
                                    0 queries
                                <?php endif; ?>
                            </span>
                        </div>
                        
                        <!-- Date Filter -->
                        <div class="filter-card">
                            <form method="get" class="row g-3 align-items-center">
                                <div class="col-md-5">
                                    <label for="date_from" class="form-label"><i class="fas fa-calendar-minus me-1"></i> From Date</label>
                                    <input type="date" name="date_from" class="form-control form-control-sm" 
                                           value="<?= h($date_from) ?>" max="<?= date('Y-m-d') ?>">
                                </div>
                                <div class="col-md-5">
                                    <label for="date_to" class="form-label"><i class="fas fa-calendar-plus me-1"></i> To Date</label>
                                    <input type="date" name="date_to" class="form-control form-control-sm" 
                                           value="<?= h($date_to) ?>" max="<?= date('Y-m-d') ?>">
                                </div>
                                <div class="col-md-2 d-flex align-items-end">
                                    <div class="d-flex gap-2 w-100">
                                        <button type="submit" class="btn btn-primary btn-sm flex-grow-1">
                                            <i class="fas fa-filter me-1"></i> Filter
                                        </button>
                                        <a href="tm_queries.php" class="btn btn-outline-secondary btn-sm" title="Clear Filter">
                                            <i class="fas fa-times"></i>
                                        </a>
                                    </div>
                                </div>
                            </form>
                        </div>

                        <!-- Queries Table -->
                        <div class="table-responsive" style="max-height: 500px; overflow-y: auto;">
                            <table class="table table-hover align-middle">
                                <thead class="sticky-top" style="background-color: #2c3e50;">
                                    <tr>
                                        <th width="120">Received Date</th>
                                        <th>Client</th>
                                        <th>Issue</th>
                                        <th>Articles</th>
                                        <th>Query Reason</th>
                                        <th width="150">Query Received Date</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if($queries && $queries->num_rows > 0): ?>
                                        <?php while($row = $queries->fetch_assoc()): ?>
                                        <tr>
                                            <!-- Received Date -->
                                            <td>
                                                <small class="fw-bold text-muted"><?= h(formatDate($row['query_date'])) ?></small>
                                            </td>
                                            <!-- Client -->
                                            <td><?= h($row['client'] ?? '—') ?></td>
                                            <!-- Issue -->
                                            <td><?= h($row['issue'] ?? '—') ?></td>
                                            <!-- Articles -->
                                            <td><?= h($row['article'] ?? '—') ?></td>
                                            <!-- Query Reason -->
                                            <td>
                                                <div class="query-text" title="<?= h($row['query']) ?>">
                                                    <?= h($row['query']) ?>
                                                </div>
                                            </td>
                                            <!-- Query Received Date -->
                                            <td>
                                                <small class="text-muted"><?= h(formatDate($row['created_at'] ?? $row['query_date'])) ?></small>
                                            </td>
                                        </tr>

                                        <!-- Response Modal -->
                                        <?php if(!empty($row['admin_response'])): ?>
                                        <div class="modal fade" id="responseModal<?= $row['id'] ?>" tabindex="-1">
                                            <div class="modal-dialog modal-lg">
                                                <div class="modal-content">
                                                    <div class="modal-header bg-primary text-white">
                                                        <h5 class="modal-title">
                                                            <i class="fas fa-comment-alt me-2"></i>Response to Query #<?= $row['id'] ?>
                                                        </h5>
                                                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                                                    </div>
                                                    <div class="modal-body">
                                                        <div class="row mb-4">
                                                            <div class="col-md-6">
                                                                <div class="card">
                                                                    <div class="card-header bg-light">
                                                                        <strong><i class="fas fa-user me-2"></i>Query Details</strong>
                                                                    </div>
                                                                    <div class="card-body">
                                                                        <p><strong>Date:</strong> <?= h(formatDate($row['query_date'])) ?></p>
                                                                        <p><strong>Status:</strong> 
                                                                            <span class="status-badge status-<?= h($row['status']) ?>">
                                                                                <?= ucfirst(str_replace('_', ' ', $row['status'])) ?>
                                                                            </span>
                                                                        </p>
                                                                        <?php if(!empty($row['response_date'])): ?>
                                                                        <p><strong>Responded:</strong> <?= h(formatDate($row['response_date'])) ?></p>
                                                                        <?php endif; ?>
                                                                    </div>
                                                                </div>
                                                            </div>
                                                        </div>
                                                        
                                                        <div class="mb-3">
                                                            <label class="form-label"><strong><i class="fas fa-question-circle me-2"></i>Your Query:</strong></label>
                                                            <div class="border p-3 bg-light rounded mb-3">
                                                                <?= nl2br(h($row['query'])) ?>
                                                            </div>
                                                        </div>
                                                        
                                                        <div class="mb-3">
                                                            <label class="form-label"><strong><i class="fas fa-reply me-2"></i>Admin Response:</strong></label>
                                                            <div class="border p-3 bg-success text-white rounded">
                                                                <?= nl2br(h($row['admin_response'])) ?>
                                                            </div>
                                                        </div>
                                                    </div>
                                                    <div class="modal-footer">
                                                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                                                            <i class="fas fa-times me-1"></i> Close
                                                        </button>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                        <?php endif; ?>
                                        <?php endwhile; ?>
                                    <?php else: ?>
                                        <tr>
                                            <td colspan="5" class="text-center py-5">
                                                <div class="text-muted">
                                                    <i class="fas fa-inbox fa-3x mb-3"></i>
                                                    <h5>No queries found</h5>
                                                    <p class="mb-0">You haven't submitted any queries yet.</p>
                                                    <small>Submit your first query using the form on the left.</small>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                        
                        <?php if($queries && $queries->num_rows > 0): ?>
                        <div class="mt-3 text-center">
                            <small class="text-muted">
                                <i class="fas fa-info-circle me-1"></i>
                                Showing <?= $queries->num_rows ?> query(ies)
                                <?php if($date_from || $date_to): ?>
                                    (filtered by date range)
                                <?php endif; ?>
                            </small>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Auto-dismiss alerts after 5 seconds
        document.addEventListener('DOMContentLoaded', function() {
            const alerts = document.querySelectorAll('.alert');
            alerts.forEach(alert => {
                setTimeout(() => {
                    if (alert.isConnected) {
                        const bsAlert = new bootstrap.Alert(alert);
                        bsAlert.close();
                    }
                }, 5000);
            });

            // Set max date for all date inputs to today
            const dateInputs = document.querySelectorAll('input[type="date"]');
            const today = new Date().toISOString().split('T')[0];
            
            dateInputs.forEach(input => {
                input.max = today;
            });

            // Auto-fill current date if empty
            const queryDateInput = document.getElementById('query_date');
            if (queryDateInput && !queryDateInput.value) {
                queryDateInput.value = today;
            }

            // Form validation
            const queryForm = document.querySelector('form[method="post"]');
            if (queryForm) {
                queryForm.addEventListener('submit', function(e) {
                    const queryTextarea = document.getElementById('query');
                    if (queryTextarea && queryTextarea.value.trim().length < 10) {
                        e.preventDefault();
                        const alertDiv = document.createElement('div');
                        alertDiv.className = 'alert alert-warning alert-dismissible fade show';
                        alertDiv.innerHTML = `
                            <i class="fas fa-exclamation-triangle me-2"></i>
                            Please provide more details in your query (at least 10 characters).
                            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                        `;
                        queryForm.insertBefore(alertDiv, queryForm.firstChild);
                        queryTextarea.focus();
                        
                        // Auto-dismiss the new alert
                        setTimeout(() => {
                            if (alertDiv.isConnected) {
                                const bsAlert = new bootstrap.Alert(alertDiv);
                                bsAlert.close();
                            }
                        }, 5000);
                    }
                });
            }

            // Auto-resize textarea as user types
            const textarea = document.getElementById('query');
            if (textarea) {
                textarea.addEventListener('input', function() {
                    this.style.height = 'auto';
                    this.style.height = (this.scrollHeight) + 'px';
                });
            }

            // Scroll to top of table when filter is applied
            const filterForm = document.querySelector('form[method="get"]');
            if (filterForm) {
                filterForm.addEventListener('submit', function() {
                    const tableSection = document.querySelector('.table-section');
                    if (tableSection) {
                        tableSection.scrollTop = 0;
                    }
                });
            }
        });
    </script>
</body>
</html>