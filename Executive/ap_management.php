<br><br><br>
<?php
// ap_management.php
require 'db.php';
if (session_status() === PHP_SESSION_NONE) { 
    session_start(); 
}

// Get current user
$current_user = $_SESSION['username'] ?? 'System';

// Initialize message variables
$message = '';
$message_type = '';

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    if (isset($_POST['add_ap'])) {
        // Add new AP record
        $date = $_POST['date'];
        $client_id = intval($_POST['client_id']);
        $issue = $mysqli->real_escape_string($_POST['issue']);
        $articles = intval($_POST['articles']);
        $dwnl = $mysqli->real_escape_string($_POST['dwnl']);
        $due_date = $_POST['due_date'];
        $remarks = $mysqli->real_escape_string($_POST['remarks']);
        $status = 'Pending';
        
        // Get client name from client_master
        $client_name = '';
        $client_query = $mysqli->query("SELECT client_name FROM client_master WHERE client_id = $client_id");
        if ($client_row = $client_query->fetch_assoc()) {
            $client_name = $mysqli->real_escape_string($client_row['client_name']);
        }
        
        $sql = "INSERT INTO ap_details (date, client_id, client_name, issue, articles, dwnl, due_date, remarks, status, created_by) 
                VALUES ('$date', $client_id, '$client_name', '$issue', $articles, '$dwnl', '$due_date', '$remarks', '$status', '$current_user')";
        
        if ($mysqli->query($sql)) {
            $message = "AP details added successfully!";
            $message_type = "success";
        } else {
            $message = "Error adding AP: " . $mysqli->error;
            $message_type = "danger";
        }
    }
    elseif (isset($_POST['update_ap'])) {
        // Update existing AP record
        $id = intval($_POST['id']);
        $date = $_POST['date'];
        $client_id = intval($_POST['client_id']);
        $issue = $mysqli->real_escape_string($_POST['issue']);
        $articles = intval($_POST['articles']);
        $dwnl = $mysqli->real_escape_string($_POST['dwnl']);
        $due_date = $_POST['due_date'];
        $remarks = $mysqli->real_escape_string($_POST['remarks']);
        $status = $mysqli->real_escape_string($_POST['status']);
        
        // Get client name from client_master
        $client_name = '';
        $client_query = $mysqli->query("SELECT client_name FROM client_master WHERE client_id = $client_id");
        if ($client_row = $client_query->fetch_assoc()) {
            $client_name = $mysqli->real_escape_string($client_row['client_name']);
        }
        
        $sql = "UPDATE ap_details SET 
                date = '$date',
                client_id = $client_id,
                client_name = '$client_name',
                issue = '$issue',
                articles = $articles,
                dwnl = '$dwnl',
                due_date = '$due_date',
                remarks = '$remarks',
                status = '$status'
                WHERE id = $id";
        
        if ($mysqli->query($sql)) {
            $message = "AP details updated successfully!";
            $message_type = "success";
        } else {
            $message = "Error updating AP: " . $mysqli->error;
            $message_type = "danger";
        }
    }
    elseif (isset($_POST['delete_ap'])) {
        // Delete AP record
        $id = intval($_POST['id']);
        
        $sql = "DELETE FROM ap_details WHERE id = $id";
        
        if ($mysqli->query($sql)) {
            $message = "AP record deleted successfully!";
            $message_type = "success";
        } else {
            $message = "Error deleting AP: " . $mysqli->error;
            $message_type = "danger";
        }
    }
}

// Get filter parameters
$filter_status = $_GET['status'] ?? '';
$filter_client = $_GET['client'] ?? '';
$filter_date_from = $_GET['date_from'] ?? '';
$filter_date_to = $_GET['date_to'] ?? '';

// Build WHERE clause for filtering
$where_clauses = [];
if (!empty($filter_status)) {
    $where_clauses[] = "ap.status = '" . $mysqli->real_escape_string($filter_status) . "'";
}
if (!empty($filter_client)) {
    $where_clauses[] = "ap.client_id = " . intval($filter_client);
}
if (!empty($filter_date_from)) {
    $where_clauses[] = "ap.date >= '" . $mysqli->real_escape_string($filter_date_from) . "'";
}
if (!empty($filter_date_to)) {
    $where_clauses[] = "ap.date <= '" . $mysqli->real_escape_string($filter_date_to) . "'";
}

$where_sql = '';
if (!empty($where_clauses)) {
    $where_sql = "WHERE " . implode(" AND ", $where_clauses);
}

// Fetch all AP records - directly from ap_details since client_name is stored there
$sql = "SELECT ap.* 
        FROM ap_details ap
        $where_sql 
        ORDER BY ap.date DESC, ap.due_date ASC";
$result = $mysqli->query($sql);
$ap_records = [];
if ($result) {
    $ap_records = $result->fetch_all(MYSQLI_ASSOC);
}

// Get active clients for dropdown
$client_result = $mysqli->query("SELECT client_id, client_name, company_name FROM client_master WHERE status = 'Active' ORDER BY client_name");
$clients = [];
if ($client_result) {
    while ($row = $client_result->fetch_assoc()) {
        $clients[] = $row;
    }
}

// Include header
include 'header.php';
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>AP Details Management</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body { background-color: #f8f9fa; }
        .card { box-shadow: 0 0.125rem 0.25rem rgba(0, 0, 0, 0.075); }
        .table th { background-color: #2c3e50; color: white; }
        .status-badge { padding: 4px 8px; border-radius: 4px; font-size: 0.85em; font-weight: 500; }
        .status-Pending { background-color: #fff3cd; color: #856404; }
        .status-In-Progress { background-color: #d1ecf1; color: #0c5460; }
        .status-Completed { background-color: #d4edda; color: #155724; }
        .status-On-Hold { background-color: #f8d7da; color: #721c24; }
        .overdue { background-color: #f8d7da !important; }
        .due-soon { background-color: #fff3cd !important; }
        .form-control-sm { height: calc(1.5em + .5rem + 2px); padding: .25rem .5rem; font-size: .875rem; }
    </style>
</head>
<body>
    <?php include 'header.php'; ?>
    
    <div class="container-fluid mt-4">
        <!-- Page Header -->
        <div class="d-flex justify-content-between align-items-center mb-4">
            <div>
                <h2><i class="fas fa-file-invoice-dollar text-primary me-2"></i>AP Details Management</h2>
                <p class="text-muted mb-0">Manage Accounts Payable details and track status</p>
            </div>
            <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addAPModal">
                <i class="fas fa-plus-circle me-2"></i>Add New AP
            </button>
        </div>

        <!-- Message Display -->
        <?php if ($message): ?>
            <div class="alert alert-<?= $message_type ?> alert-dismissible fade show" role="alert">
                <?= $message ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <!-- Filter Section -->
        <div class="card mb-4">
            <div class="card-body">
                <h5 class="card-title mb-3">Filter AP Records</h5>
                <form method="GET" class="row g-3">
                    <div class="col-md-3">
                        <label class="form-label">Status</label>
                        <select name="status" class="form-select form-select-sm">
                            <option value="">All Status</option>
                            <option value="Pending" <?= $filter_status == 'Pending' ? 'selected' : '' ?>>Pending</option>
                            <option value="In Progress" <?= $filter_status == 'In Progress' ? 'selected' : '' ?>>In Progress</option>
                            <option value="Completed" <?= $filter_status == 'Completed' ? 'selected' : '' ?>>Completed</option>
                            <option value="On Hold" <?= $filter_status == 'On Hold' ? 'selected' : '' ?>>On Hold</option>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Client</label>
                        <select name="client" class="form-select form-select-sm">
                            <option value="">All Clients</option>
                            <?php foreach ($clients as $client): ?>
                                <option value="<?= $client['client_id'] ?>" <?= $filter_client == $client['client_id'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($client['client_name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">From Date</label>
                        <input type="date" name="date_from" class="form-control form-control-sm" value="<?= htmlspecialchars($filter_date_from) ?>">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">To Date</label>
                        <input type="date" name="date_to" class="form-control form-control-sm" value="<?= htmlspecialchars($filter_date_to) ?>">
                    </div>
                    <div class="col-12">
                        <button type="submit" class="btn btn-primary btn-sm me-2">
                            <i class="fas fa-filter me-1"></i> Apply Filter
                        </button>
                        <a href="ap_management.php" class="btn btn-secondary btn-sm">
                            <i class="fas fa-times me-1"></i> Clear
                        </a>
                    </div>
                </form>
            </div>
        </div>

        <!-- AP Records Table -->
        <div class="card">
            <div class="card-header bg-dark text-white">
                <div class="d-flex justify-content-between align-items-center">
                    <h5 class="mb-0"><i class="fas fa-list me-2"></i>AP Records</h5>
                    <span class="badge bg-light text-dark"><?= count($ap_records) ?> records</span>
                </div>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>#</th>
                                <th>Date</th>
                                <th>Client</th>
                                <th>Issue</th>
                                <th>Articles</th>
                                <th>DWNL</th>
                                <th>Due Date</th>
                                <th>Status</th>
                                <th>Remarks</th>
                                <th>Created By</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($ap_records)): ?>
                                <tr>
                                    <td colspan="11" class="text-center py-4 text-muted">
                                        <i class="fas fa-inbox fa-3x mb-3"></i>
                                        <h5>No AP records found</h5>
                                        <p>Click "Add New AP" to create your first record</p>
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php $counter = 1; ?>
                                <?php foreach ($ap_records as $ap): ?>
                                    <?php
                                    // Check if overdue or due soon
                                    $due_date = new DateTime($ap['due_date']);
                                    $today = new DateTime();
                                    $interval = $today->diff($due_date);
                                    $days_remaining = $interval->days * ($interval->invert ? -1 : 1);
                                    
                                    $row_class = '';
                                    if ($ap['status'] != 'Completed') {
                                        if ($days_remaining < 0) {
                                            $row_class = 'overdue';
                                        } elseif ($days_remaining <= 2) {
                                            $row_class = 'due-soon';
                                        }
                                    }
                                    ?>
                                    <tr class="<?= $row_class ?>">
                                        <td class="fw-bold"><?= $counter++ ?></td>
                                        <td><?= date('d-M-Y', strtotime($ap['date'])) ?></td>
                                        <td><strong><?= htmlspecialchars($ap['client_name']) ?></strong></td>
                                        <td><?= htmlspecialchars($ap['issue']) ?></td>
                                        <td class="text-center"><?= $ap['articles'] ?></td>
                                        <td class="text-center"><?= htmlspecialchars($ap['dwnl']) ?></td>
                                        <td>
                                            <?= date('d-M-Y', strtotime($ap['due_date'])) ?>
                                            <?php if ($days_remaining < 0): ?>
                                                <span class="badge bg-danger ms-1">Overdue</span>
                                            <?php elseif ($days_remaining <= 2): ?>
                                                <span class="badge bg-warning ms-1">Due Soon</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <span class="status-badge status-<?= str_replace(' ', '-', $ap['status']) ?>">
                                                <?= $ap['status'] ?>
                                            </span>
                                        </td>
                                        <td class="small"><?= htmlspecialchars($ap['remarks'] ?: '-') ?></td>
                                        <td class="small"><?= htmlspecialchars($ap['created_by']) ?></td>
                                        <td>
                                            <div class="btn-group btn-group-sm" role="group">
                                                  <a href="ap_progress.php?ap_id=<?= $ap['id'] ?>" class="btn btn-outline-info" title="View Progress">
            <i class="fas fa-tasks"></i>
        </a>
      
                                                <button type="button" class="btn btn-outline-primary" 
                                                        data-bs-toggle="modal" data-bs-target="#editAPModal"
                                                        onclick="editAP(<?= htmlspecialchars(json_encode($ap)) ?>)">
                                                    <i class="fas fa-edit"></i>
                                                </button>
                                                <button type="button" class="btn btn-outline-danger" 
                                                        data-bs-toggle="modal" data-bs-target="#deleteAPModal"
                                                        onclick="setDeleteId(<?= $ap['id'] ?>)">
                                                    <i class="fas fa-trash"></i>
                                                </button>
                                            </div>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <!-- Add AP Modal -->
    <div class="modal fade" id="addAPModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title"><i class="fas fa-plus-circle me-2"></i>Add New AP Details</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST" action="">
                    <div class="modal-body">
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label">Date *</label>
                                <input type="date" name="date" class="form-control" value="<?= date('Y-m-d') ?>" required>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Due Date *</label>
                                <input type="date" name="due_date" class="form-control" required>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Client *</label>
                                <select name="client_id" class="form-select" required>
                                    <option value="">Select Client</option>
                                    <?php foreach ($clients as $client): ?>
                                        <option value="<?= $client['client_id'] ?>">
                                            <?= htmlspecialchars($client['client_name']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Issue *</label>
                                <input type="text" name="issue" class="form-control" placeholder="Enter issue description" required>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Articles</label>
                                <input type="number" name="articles" class="form-control" placeholder="Number of articles" value="0">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">DWNL</label>
                                <input type="text" name="dwnl" class="form-control" placeholder="Enter DWNL reference">
                            </div>
                            <div class="col-12">
                                <label class="form-label">Remarks</label>
                                <textarea name="remarks" class="form-control" rows="3" placeholder="Additional remarks or notes"></textarea>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" name="add_ap" class="btn btn-primary">Save AP Details</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Edit AP Modal -->
    <div class="modal fade" id="editAPModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header bg-warning text-dark">
                    <h5 class="modal-title"><i class="fas fa-edit me-2"></i>Edit AP Details</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST" action="">
                    <input type="hidden" name="id" id="edit_id">
                    <div class="modal-body">
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label">Date *</label>
                                <input type="date" name="date" id="edit_date" class="form-control" required>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Due Date *</label>
                                <input type="date" name="due_date" id="edit_due_date" class="form-control" required>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Client *</label>
                                <select name="client_id" id="edit_client_id" class="form-select" required>
                                    <option value="">Select Client</option>
                                    <?php foreach ($clients as $client): ?>
                                        <option value="<?= $client['client_id'] ?>">
                                            <?= htmlspecialchars($client['client_name']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Issue *</label>
                                <input type="text" name="issue" id="edit_issue" class="form-control" required>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Articles</label>
                                <input type="number" name="articles" id="edit_articles" class="form-control">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">DWNL</label>
                                <input type="text" name="dwnl" id="edit_dwnl" class="form-control">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Status</label>
                                <select name="status" id="edit_status" class="form-select">
                                    <option value="Pending">Pending</option>
                                    <option value="In Progress">In Progress</option>
                                    <option value="Completed">Completed</option>
                                    <option value="On Hold">On Hold</option>
                                </select>
                            </div>
                            <div class="col-12">
                                <label class="form-label">Remarks</label>
                                <textarea name="remarks" id="edit_remarks" class="form-control" rows="3"></textarea>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" name="update_ap" class="btn btn-warning">Update AP Details</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Delete Confirmation Modal -->
    <div class="modal fade" id="deleteAPModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header bg-danger text-white">
                    <h5 class="modal-title"><i class="fas fa-trash me-2"></i>Confirm Delete</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST" action="">
                    <input type="hidden" name="id" id="delete_id">
                    <div class="modal-body">
                        <p>Are you sure you want to delete this AP record?</p>
                        <p class="text-danger"><strong>This action cannot be undone.</strong></p>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" name="delete_ap" class="btn btn-danger">Delete Record</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- JavaScript -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        function editAP(ap) {
            document.getElementById('edit_id').value = ap.id;
            document.getElementById('edit_date').value = ap.date;
            document.getElementById('edit_client_id').value = ap.client_id;
            document.getElementById('edit_issue').value = ap.issue;
            document.getElementById('edit_articles').value = ap.articles;
            document.getElementById('edit_dwnl').value = ap.dwnl;
            document.getElementById('edit_due_date').value = ap.due_date;
            document.getElementById('edit_status').value = ap.status;
            document.getElementById('edit_remarks').value = ap.remarks || '';
        }

        function setDeleteId(id) {
            document.getElementById('delete_id').value = id;
        }
        
        // Auto-set due date to 7 days from selected date
        document.addEventListener('DOMContentLoaded', function() {
            const dateInput = document.querySelector('input[name="date"]');
            const dueDateInput = document.querySelector('input[name="due_date"]');
            
            if (dateInput && dueDateInput) {
                dateInput.addEventListener('change', function() {
                    if (!dueDateInput.value) {
                        const selectedDate = new Date(this.value);
                        selectedDate.setDate(selectedDate.getDate() + 7);
                        const nextWeek = selectedDate.toISOString().split('T')[0];
                        dueDateInput.value = nextWeek;
                    }
                });
            }
        });
    </script>
</body>
</html>