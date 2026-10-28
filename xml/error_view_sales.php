<?php
require 'db.php';
if (session_status() === PHP_SESSION_NONE) { 
    session_start(); 
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
    $base = 'error_entry.php';
    if (!empty($params)) $base .= '?' . http_build_query($params);
    header("Location: $base");
    exit;
}

// Fetch dropdown data from master tables
$teams = $mysqli->query("SELECT team_name FROM team_master WHERE status = 'Active' ORDER BY team_name");
$employees = $mysqli->query("SELECT employee_name FROM employee_master WHERE status = 'Active' ORDER BY employee_name");
$clients = $mysqli->query("SELECT client_name FROM client_master WHERE status = 'Active' ORDER BY client_name");

// Get search and filter parameters
$q = trim($_GET['q'] ?? '');
$filter_client = trim($_GET['client'] ?? '');
$filter_department = trim($_GET['department'] ?? '');
$filter_error_type = trim($_GET['error_type'] ?? '');
$sort = $_GET['sort'] ?? 'created_date';
$dir = strtolower($_GET['dir'] ?? 'desc');

// Build query
$sql = "SELECT id, week, error_type, error_subtype, client, yn, department, employee, comment, created_date 
        FROM error_log WHERE status = 'Active'";
$cond = [];
$params = [];
$types = '';

if ($q !== '') {
    $cond[] = "(client LIKE ? OR employee LIKE ? OR error_type LIKE ? OR comment LIKE ? OR department LIKE ?)";
    $like = "%$q%";
    $params = array_merge($params, [$like, $like, $like, $like, $like]);
    $types .= 'sssss';
}

if ($filter_client !== '') {
    $cond[] = "client = ?";
    $params[] = $filter_client;
    $types .= 's';
}

if ($filter_department !== '') {
    $cond[] = "department = ?";
    $params[] = $filter_department;
    $types .= 's';
}

if ($filter_error_type !== '') {
    $cond[] = "error_type = ?";
    $params[] = $filter_error_type;
    $types .= 's';
}

if (!empty($cond)) {
    $sql .= " AND " . implode(" AND ", $cond);
}

$sql .= " ORDER BY $sort $dir";

// Execute query
if (!empty($types)) {
    $stmt = $mysqli->prepare($sql);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $entries = $stmt->get_result();
} else {
    $entries = $mysqli->query($sql);
}

// Get unique values for filter dropdowns
$unique_clients = $mysqli->query("SELECT DISTINCT client FROM error_log WHERE client != '' ORDER BY client");
$unique_departments = $mysqli->query("SELECT DISTINCT department FROM error_log WHERE department != '' ORDER BY department");
$unique_error_types = $mysqli->query("SELECT DISTINCT error_type FROM error_log WHERE error_type != '' ORDER BY error_type");

// If query fails, show empty result
if (!$entries) {
    $entries = false;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Error Log Management</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
    <style>
        body {
            background-color: #f8f9fa;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }
        .main-content {
            padding: 20px;
            max-width: 1600px;
            margin: 0 auto;
        }
        .table th {
            background-color: #2c3e50;
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
        .btn-group-sm > .btn {
            padding: 0.25rem 0.5rem;
        }
        .form-label {
            font-weight: 500;
        }
        .select2-container--default .select2-selection--single {
            height: 38px;
            padding: 5px;
            border: 1px solid #ced4da;
        }
        .select2-container--default .select2-selection--multiple {
            border: 1px solid #ced4da;
        }
        .badge {
            font-size: 0.85em;
        }
        .table-responsive {
            overflow-x: auto;
        }
        .table {
            width: 100%;
            border-collapse: collapse;
        }
        .table th, .table td {
            border: 1px solid #dee2e6;
            padding: 8px;
        }
        .table thead th {
            border-bottom: 2px solid #dee2e6;
        }
        .table-striped tbody tr:nth-of-type(odd) {
            background-color: rgba(0, 0, 0, 0.02);
        }
        .table-hover tbody tr:hover {
            background-color: rgba(0, 0, 0, 0.04);
        }
    </style>
</head>
<body>
    <?php require 'header.php'; ?>
    
    <div class="main-content">
        <?php if (!empty($_GET['msg'])): ?>
        <div class="alert alert-<?= h($_GET['type'] ?? 'info') ?> alert-dismissible fade show" role="alert">
            <?= h($_GET['msg']) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <?php endif; ?>

        <!-- View Mode Only -->
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h4><i class="fas fa-bug me-2"></i>Error Log Management</h4>
            <div>
                <button class="btn btn-outline-primary btn-sm" onclick="exportToExcel()">
                    <i class="fas fa-file-excel me-1"></i> Export Excel
                </button>
            </div>
        </div>

        <!-- Search and Filter Form -->
        <div class="card mb-4">
            <div class="card-body">
                <form method="get" class="row g-3 align-items-center" id="searchForm">
                    <div class="col-md-2">
                        <input type="text" name="q" class="form-control form-control-sm" 
                               placeholder="Global search..." value="<?= h($q) ?>">
                    </div>
                    <div class="col-md-2">
                        <select name="client" class="form-select form-select-sm select2-sm">
                            <option value="">All Clients</option>
                            <?php if($unique_clients && $unique_clients->num_rows > 0): ?>
                                <?php $unique_clients->data_seek(0); ?>
                                <?php while($client = $unique_clients->fetch_assoc()): ?>
                                <option value="<?= h($client['client']) ?>" 
                                    <?= $filter_client === $client['client'] ? 'selected' : '' ?>>
                                    <?= h($client['client']) ?>
                                </option>
                                <?php endwhile; ?>
                            <?php endif; ?>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <select name="department" class="form-select form-control-sm select2-sm">
                            <option value="">All Departments</option>
                            <?php if($unique_departments && $unique_departments->num_rows > 0): ?>
                                <?php $unique_departments->data_seek(0); ?>
                                <?php while($dept = $unique_departments->fetch_assoc()): ?>
                                <option value="<?= h($dept['department']) ?>" 
                                    <?= $filter_department === $dept['department'] ? 'selected' : '' ?>>
                                    <?= h($dept['department']) ?>
                                </option>
                                <?php endwhile; ?>
                            <?php endif; ?>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <select name="error_type" class="form-select form-control-sm">
                            <option value="">All Error Types</option>
                            <?php if($unique_error_types && $unique_error_types->num_rows > 0): ?>
                                <?php $unique_error_types->data_seek(0); ?>
                                <?php while($err = $unique_error_types->fetch_assoc()): ?>
                                <option value="<?= h($err['error_type']) ?>" 
                                    <?= $filter_error_type === $err['error_type'] ? 'selected' : '' ?>>
                                    <?= h($err['error_type']) ?>
                                </option>
                                <?php endwhile; ?>
                            <?php endif; ?>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <select name="sort" class="form-select form-control-sm">
                            <option value="created_date" <?= $sort === 'created_date' ? 'selected' : '' ?>>Sort by Date</option>
                            <option value="week" <?= $sort === 'week' ? 'selected' : '' ?>>Sort by Week</option>
                            <option value="client" <?= $sort === 'client' ? 'selected' : '' ?>>Sort by Client</option>
                            <option value="error_type" <?= $sort === 'error_type' ? 'selected' : '' ?>>Sort by Error Type</option>
                        </select>
                        <select name="dir" class="form-select form-control-sm mt-1">
                            <option value="desc" <?= $dir === 'desc' ? 'selected' : '' ?>>Descending</option>
                            <option value="asc" <?= $dir === 'asc' ? 'selected' : '' ?>>Ascending</option>
                        </select>
                    </div>
                    <div class="col-md-12 mt-2">
                        <button type="submit" class="btn btn-primary btn-sm me-2">
                            <i class="fas fa-search me-1"></i> Search
                        </button>
                        <a href="error_entry.php" class="btn btn-secondary btn-sm me-2">
                            <i class="fas fa-times me-1"></i> Clear
                        </a>
                        <span class="text-muted">
                            <i class="fas fa-info-circle me-1"></i>
                            <?php if($entries && $entries->num_rows > 0): ?>
                                <?= $entries->num_rows ?> entries found
                            <?php else: ?>
                                No entries found
                            <?php endif; ?>
                        </span>
                    </div>
                </form>
            </div>
        </div>

        <!-- Error Log Table -->
        <div class="card">
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0 table-striped" id="errorLogTable">
                        <thead class="table-light">
                            <tr>
                                <th width="50">S.No</th>
                                <th width="100">Week</th>
                                <th width="120">Error Type</th>
                                <th width="120">Error Subtype</th>
                                <th>Client Name</th>
                                <th width="80">Y/N</th>
                                <th width="120">Department</th>
                                <th>Employee Name</th>
                                <th width="150">Comment</th>
                                <th width="120">Date</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if($entries && $entries->num_rows > 0): ?>
                                <?php $counter = 1; ?>
                                <?php while($row = $entries->fetch_assoc()): ?>
                                <tr>
                                    <td class="fw-bold"><?= $counter++ ?></td>
                                    <td>
                                        <span class="badge bg-primary"><?= h($row['week']) ?></span>
                                    </td>
                                    <td>
                                        <span class="fw-semibold"><?= h($row['error_type']) ?></span>
                                    </td>
                                    <td>
                                        <?php if(!empty($row['error_subtype'])): ?>
                                            <span class="badge bg-info text-dark"><?= h($row['error_subtype']) ?></span>
                                        <?php else: ?>
                                            <span class="text-muted">—</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <span class="fw-semibold"><?= h($row['client']) ?></span>
                                    </td>
                                    <td>
                                        <?php if($row['yn'] === 'Yes'): ?>
                                            <span class="badge bg-success">Yes</span>
                                        <?php elseif($row['yn'] === 'No'): ?>
                                            <span class="badge bg-danger">No</span>
                                        <?php else: ?>
                                            <span class="text-muted">—</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if(!empty($row['department'])): ?>
                                            <span class="badge bg-secondary"><?= h($row['department']) ?></span>
                                        <?php else: ?>
                                            <span class="text-muted">—</span>
                                        <?php endif; ?>
                                    </td>
                                    <td><?= h($row['employee'] ?: '—') ?></td>
                                    <td><?= h($row['comment'] ?: '—') ?></td>
                                    <td>
                                        <small class="text-muted"><?= h(formatDate($row['created_date'])) ?></small>
                                    </td>
                                </tr>
                                <?php endwhile; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="10" class="text-center py-5">
                                        <div class="text-muted">
                                            <i class="fas fa-clipboard-list fa-3x mb-3"></i>
                                            <h5>No error log entries found</h5>
                                            <p class="mb-0">No entries to display</p>
                                        </div>
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/xlsx@0.18.5/dist/xlsx.full.min.js"></script>
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

            // Initialize Select2 for filter dropdowns
            $('.select2-sm').select2({
                width: '100%',
                placeholder: 'Select',
                minimumResultsForSearch: 10
            });

            // Auto-submit form on filter changes
            const filterSelects = document.querySelectorAll('select[name="sort"]');
            filterSelects.forEach(select => {
                select.addEventListener('change', function() {
                    this.form.submit();
                });
            });
        });
        
        // Export to Excel function
        function exportToExcel() {
            const table = document.getElementById('errorLogTable');
            if (!table) return;
            
            // Create a new table for export
            const exportTable = document.createElement('table');
            
            // Clone the header row
            const originalHeader = table.rows[0];
            const exportHeader = document.createElement('tr');
            
            for (let i = 0; i < originalHeader.cells.length; i++) {
                const th = document.createElement('th');
                th.textContent = originalHeader.cells[i].textContent;
                exportHeader.appendChild(th);
            }
            exportTable.appendChild(exportHeader);
            
            // Process data rows
            const dataRows = table.querySelectorAll('tbody tr');
            dataRows.forEach(originalRow => {
                // Skip empty rows (no entries message)
                if (originalRow.cells.length <= 1) return;
                
                const exportRow = document.createElement('tr');
                
                for (let i = 0; i < originalRow.cells.length; i++) {
                    const td = document.createElement('td');
                    const originalCell = originalRow.cells[i];
                    
                    // Extract text content, handling badges and other HTML elements
                    let cellText = '';
                    
                    if (i === 0) { // S.No column
                        cellText = originalCell.textContent.trim();
                    } else if (i === 1) { // Week column (has badge)
                        const badge = originalCell.querySelector('.badge');
                        cellText = badge ? badge.textContent.trim() : originalCell.textContent.trim();
                    } else if (i === 3) { // Error Subtype column (has badge)
                        const badge = originalCell.querySelector('.badge');
                        cellText = badge ? badge.textContent.trim() : originalCell.textContent.trim();
                    } else if (i === 5) { // Y/N column (has badges)
                        const badge = originalCell.querySelector('.badge');
                        cellText = badge ? badge.textContent.trim() : originalCell.textContent.trim();
                    } else if (i === 6) { // Department column (has badge)
                        const badge = originalCell.querySelector('.badge');
                        cellText = badge ? badge.textContent.trim() : originalCell.textContent.trim();
                    } else if (i === 8) { // Date column (has small tag)
                        const small = originalCell.querySelector('small');
                        cellText = small ? small.textContent.trim() : originalCell.textContent.trim();
                    } else {
                        // For other columns, get the text content
                        if (originalCell.querySelector('.fw-semibold')) {
                            cellText = originalCell.querySelector('.fw-semibold').textContent.trim();
                        } else {
                            cellText = originalCell.textContent.trim();
                        }
                    }
                    
                    td.textContent = cellText;
                    exportRow.appendChild(td);
                }
                
                exportTable.appendChild(exportRow);
            });
            
            // Create worksheet
            const ws = XLSX.utils.table_to_sheet(exportTable);
            
            // Set column widths
            const wscols = [
                {wch: 8},   // S.No
                {wch: 15},  // Week
                {wch: 20},  // Error Type
                {wch: 20},  // Error Subtype
                {wch: 30},  // Client Name
                {wch: 10},  // Y/N
                {wch: 20},  // Department
                {wch: 25},  // Employee Name
                {wch: 40},  // Comment
                {wch: 15}   // Date
            ];
            ws['!cols'] = wscols;
            
            const wb = XLSX.utils.book_new();
            XLSX.utils.book_append_sheet(wb, ws, "Error Log");
            
            // Generate filename
            const date = new Date().toISOString().slice(0, 10);
            const filename = `Error_Log_${date}.xlsx`;
            
            XLSX.writeFile(wb, filename);
        }
        
        // Quick search in table (client-side)
        document.addEventListener('DOMContentLoaded', function() {
            const searchInput = document.querySelector('input[name="q"]');
            if (searchInput) {
                // Debounce function for performance
                let debounceTimer;
                searchInput.addEventListener('input', function() {
                    clearTimeout(debounceTimer);
                    debounceTimer = setTimeout(() => {
                        const searchTerm = this.value.toLowerCase();
                        const rows = document.querySelectorAll('#errorLogTable tbody tr');
                        
                        rows.forEach(row => {
                            const text = row.textContent.toLowerCase();
                            row.style.display = text.includes(searchTerm) ? '' : 'none';
                        });
                    }, 300);
                });
            }
        });
    </script>
</body>
</html>