<?php require 'header.php';?>
<div class="main-content">
    <div class="job-master-container">

<?php
require 'db.php';
//session_start();

function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES); }

// Check authentication
$current_user = $_SESSION['username'] ?? '';
if (!$current_user) {
    header('Location: index.php'); 
    exit;
}


if (isset($_POST['action']) && $_POST['action'] === 'delete_all') {
    // Delete all data from input_output table
    $sql = "DELETE FROM input_output";
    $stmt = $mysqli->prepare($sql);
    
    if ($stmt->execute()) {
        $message = "All data deleted successfully from input/output sheet";
        $message_type = "success";
    } else {
        $message = "Error deleting data: " . $stmt->error;
        $message_type = "danger";
    }
    $stmt->close();
}

// --- Handle Delete Action ---
if (isset($_GET['delete_id'])) {
    $delete_id = (int)$_GET['delete_id'];
    $stmt = $mysqli->prepare("DELETE FROM input_output WHERE id = ?");
    $stmt->bind_param("i", $delete_id);
    if ($stmt->execute()) {
        $_SESSION['message'] = "Record deleted successfully";
    } else {
        $_SESSION['message'] = "Error deleting record: " . $stmt->error;
    }
    $stmt->close();
    header("Location: ".$_SERVER['PHP_SELF']);
    exit;
}

// --- Search Parameters ---
$search_client = $_GET['search_client'] ?? '';
$search_journal = $_GET['search_journal'] ?? '';
$search_article = $_GET['search_article'] ?? '';

// Build WHERE clause for search
$where_conditions = [];
$params = [];
$types = '';

if (!empty($search_client)) {
    $where_conditions[] = "client LIKE ?";
    $params[] = "%$search_client%";
    $types .= 's';
}

if (!empty($search_journal)) {
    $where_conditions[] = "journal LIKE ?";
    $params[] = "%$search_journal%";
    $types .= 's';
}

if (!empty($search_article)) {
    $where_conditions[] = "articles LIKE ?";
    $params[] = "%$search_article%";
    $types .= 's';
}

$where_sql = "";
if (!empty($where_conditions)) {
    $where_sql = " WHERE " . implode(" AND ", $where_conditions);
}

// Main query
$sql = "SELECT 
    id, job_date, le_date, ce_date, client, journal, articles, pages, eqn, 
    images, tables_count, ce_done_by, le_done_by, pag_done_by, qc_done_by, 
    due_date, rtat, atat, dispatch_date, upload_by, file_path, 
    client_status, ce_status, le_status, pgn_status, qc_status, math
FROM input_output
$where_sql
ORDER BY job_date DESC";

$stmt = $mysqli->prepare($sql);
if (!$stmt) die("Prepare failed: ".$mysqli->error);

if (!empty($params)) {
    $stmt->bind_param($types, ...$params);
}

$stmt->execute();
$results = $stmt->get_result();

?>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css"/>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons/font/bootstrap-icons.css"/>
<link rel="stylesheet" href="https://cdn.datatables.net/2.0.8/css/dataTables.bootstrap5.css">

<style>
.dataTables_wrapper .dt-paging a.current { background-color: #007bff !important; color: white !important; }
table td,th { vertical-align: middle; padding: 6px 8px; white-space: nowrap; font-size: 0.9rem; }
.status-default { background-color: #5bc0de; color: white; font-weight: 600; border-radius: 12px; padding: 0.3em 0.8em; font-size: 0.9em; display: inline-block; }
.btn-action { padding: 0.25rem 0.5rem; font-size: 0.8rem; margin: 1px; }
.search-form .form-control { font-size: 0.9rem; }
</style>

 <style>
        .danger-zone {
            border: 2px solid #dc3545;
            border-radius: 10px;
            padding: 20px;
            background-color: #f8d7da;
        }
        .btn-delete {
            background-color: #dc3545;
            color: white;
            border: none;
            padding: 10px 20px;
            font-size: 16px;
        }
        .btn-delete:hover {
            background-color: #c82333;
            color: white;
        }
    </style>

<div class="container-fluid mt-3">
    <h2>Input/Output Data (Datatable View)</h2>

    <?php if (isset($_SESSION['message'])): ?>
        <div class="alert alert-info alert-dismissible fade show" role="alert">
            <?= h($_SESSION['message']) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
        <?php unset($_SESSION['message']); ?>
    <?php endif; ?>

    <!-- Search Form -->
    <div class="card mb-3">
        <div class="card-header">
            <h6 class="mb-0">Search Filters</h6>
        </div>
        <div class="card-body">
            <form method="GET" class="row g-2 search-form">
                <div class="col-md-3">
                    <input type="text" name="search_client" class="form-control form-control-sm" 
                           placeholder="Search by Client" value="<?= h($search_client) ?>">
                </div>
                <div class="col-md-3">
                    <input type="text" name="search_journal" class="form-control form-control-sm" 
                           placeholder="Search by Journal" value="<?= h($search_journal) ?>">
                </div>
                <div class="col-md-3">
                    <input type="text" name="search_article" class="form-control form-control-sm" 
                           placeholder="Search by Article" value="<?= h($search_article) ?>">
                </div>
                <div class="col-md-3">
                    <button type="submit" class="btn btn-primary btn-sm me-1">
                        <i class="bi bi-search"></i> Search
                    </button>
                    <a href="<?= $_SERVER['PHP_SELF'] ?>" class="btn btn-secondary btn-sm">
                        <i class="bi bi-arrow-clockwise"></i> Clear
                    </a>
                </div>
            </form>
        </div>
    </div>

    <div class="row mb-3">
        <div class="col-12 d-flex justify-content-between">
            <div>
                <!--a href="add_job.php" class="btn btn-success btn-sm">
                    <i class="bi bi-plus-circle"></i> Add New Job
                </a-->
                  <form method="post" id="deleteForm" onsubmit="return confirmDelete()">
                                <input type="hidden" name="action" value="delete_all">
                                <button type="submit" class="btn btn-delete">
                                    <i class="fas fa-trash me-1"></i>
                                    Delete All Data
                                </button>
                            </form>

            </div>
            <div>
                <button type="button" onclick="exportTableToExcel('dataTable','job_management')" class="btn btn-success btn-sm me-2">
                    <i class="bi bi-download"></i> Export
                </button>
                <button type="button" class="btn btn-info btn-sm text-white" data-bs-toggle="modal" data-bs-target="#importModal">
                    <i class="bi bi-file-earmark-arrow-up"></i> Import
                </button>
                            </div>
        </div>
    </div>

    <div class="table-responsive">
        <table class="table table-striped table-bordered" id="dataTable" style="width:100%">
            <thead class="table-light">
                <tr>
                    <th>S.No</th>
                    <th>Job Date</th>
                    <th>LE Date</th>
                    <th>CE Date</th>
                    <th>Client</th>
                    <th>Journal</th>
                    <th>Articles</th>
                    <th>Pages</th>
                    <th>EQN</th>
                    <th>Images</th>
                    <th>Tables</th>
                    <th>Math</th>
                    <th>CE By</th>
                    <th>LE By</th>
                    <th>TS By</th>
                    <th>QC By</th>
                    <th>Due Date</th>
                    <th>R-TAT</th>
                    <th>A-TAT</th>
                    <th>Dispatch</th>
                    <th>Upload By</th>
                    <th>Path</th>
                    <th>Client Status</th>
                    <th>CE Status</th>
                    <th>LE Status</th>
                    <th>PGN Status</th>
                    <th>QC Status</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($results->num_rows > 0): while($row = $results->fetch_assoc()): ?>
                <tr>
                    <td><?= h($row['id']) ?></td>
                    <td><?= h($row['job_date']) ?></td>
                    <td><?= h($row['le_date']) ?></td>
                    <td><?= h($row['ce_date']) ?></td>
                    <td><?= h($row['client']) ?></td>
                    <td><?= h($row['journal']) ?></td>
                    <td><?= h($row['articles']) ?></td>
                    <td><?= h($row['pages']) ?></td>
                    <td><?= h($row['eqn']) ?></td>
                    <td><?= h($row['images']) ?></td>
                    <td><?= h($row['tables_count']) ?></td>
                    <td><?= h($row['math']) ?></td>
                    <td><?= h($row['ce_done_by']) ?></td>
                    <td><?= h($row['le_done_by']) ?></td>
                    <td><?= h($row['pag_done_by']) ?></td>
                    <td><?= h($row['qc_done_by']) ?></td>
                    <td><?= h($row['due_date']) ?></td>
                    <td><?= h($row['rtat']) ?></td>
                    <td><?= h($row['atat']) ?></td>
                    <td><?= h($row['dispatch_date']) ?></td>
                    <td><?= h($row['upload_by']) ?></td>
                    <td><?= !empty($row['file_path']) ? '<a href="'.h($row['file_path']).'" target="_blank">File</a>' : '—' ?></td>
                    <td><span class="status-default"><?= h($row['client_status'] ?? 'N/A') ?></span></td>
                    <td><span class="status-default"><?= h($row['ce_status'] ?? 'N/A') ?></span></td>
                    <td><span class="status-default"><?= h($row['le_status'] ?? 'N/A') ?></span></td>
                    <td><span class="status-default"><?= h($row['pgn_status'] ?? 'N/A') ?></span></td>
                    <td><span class="status-default"><?= h($row['qc_status'] ?? 'N/A') ?></span></td>
                    <td>
                        <div class="btn-group" role="group">
                            <!--a href="edit_job.php?id=<?= $row['id'] ?>" class="btn btn-warning btn-sm btn-action" title="Edit">
                                <i class="bi bi-pencil"></i>
                            </a-->
                            <button type="button" class="btn btn-danger btn-sm btn-action" 
                                    onclick="confirmDelete(<?= $row['id'] ?>)" title="Delete">
                                <i class="bi bi-trash"></i>
                            </button>
                        </div>
                    </td>
                </tr>
                <?php endwhile; else: ?>
                <tr>
                    <td></td><td></td><td></td><td></td><td></td><td></td><td></td><td></td><td></td><td></td><td></td><td></td><td></td><td></td>
                    <td class="text-center">No records found</td>
                    <td></td><td></td><td></td><td></td><td></td><td></td><td></td><td></td><td></td><td></td><td></td><td></td><td></td>
                </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Import Modal -->
<div class="modal fade" id="importModal" tabindex="-1" aria-labelledby="importModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <form action="upload_excel.php" method="POST" enctype="multipart/form-data">
                <div class="modal-header">
                    <h5 class="modal-title" id="importModalLabel">Upload Excel File</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <p>Select an Excel file (.xlsx, .xls, .csv) to import new jobs.</p>
                    <input type="file" name="import_file" accept=".xlsx,.xls,.csv" class="form-control" required/>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                    <button type="submit" name="save_excel_data" class="btn btn-primary">
                        <i class="bi bi-cloud-upload"></i> Upload & Import
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.datatables.net/2.0.8/js/dataTables.js"></script>
<script src="https://cdn.datatables.net/2.0.8/js/dataTables.bootstrap5.js"></script>

<script>
$(document).ready(function() {
    // Initialize Datatables
    $('#dataTable').DataTable({
        scrollX: true,
        pageLength: 25,
        order: [[1, 'desc']],
        // Preserve search filters when using DataTables search
        initComplete: function() {
            // Apply existing search values to DataTables search
            var api = this.api();
            
            // Set individual column searches if needed
            <?php if (!empty($search_client)): ?>
                api.column(4).search('<?= h($search_client) ?>');
            <?php endif; ?>
            
            <?php if (!empty($search_journal)): ?>
                api.column(5).search('<?= h($search_journal) ?>');
            <?php endif; ?>
            
            <?php if (!empty($search_article)): ?>
                api.column(6).search('<?= h($search_article) ?>');
            <?php endif; ?>
            
            api.draw();
        }
    });
});

function exportTableToExcel(tableID, filename='') {
    var table = document.getElementById(tableID);
    var html = table.outerHTML.replace(/ /g, '%20');
    var downloadLink = document.createElement("a");
    filename = filename ? filename + '.xls' : 'export.xls';
    downloadLink.href = 'data:application/vnd.ms-excel,' + html;
    downloadLink.download = filename;
    document.body.appendChild(downloadLink);
    downloadLink.click();
    document.body.removeChild(downloadLink);
}

function confirmDelete(id) {
    if (confirm('Are you sure you want to delete this record? This action cannot be undone.')) {
        window.location.href = '<?= $_SERVER['PHP_SELF'] ?>?delete_id=' + id;
    }
}
</script>

</div>
</div>