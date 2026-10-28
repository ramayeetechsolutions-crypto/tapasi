<br><br><br>
<?php

error_reporting(E_ALL);
ini_set('display_errors', 1);
session_start();
require 'db.php';

// Handle Template Update
if (isset($_POST['update_template'])) {
    $client_id = (int)$_POST['client_id'];
    $template = $_POST['template'] ?? '';
    
    $stmt = $mysqli->prepare("UPDATE client_master SET template = ? WHERE client_id = ?");
    
    if (!$stmt) {
        $_SESSION['message'] = "Prepare failed: " . $mysqli->error;
    } else {
        $stmt->bind_param("si", $template, $client_id);
        if ($stmt->execute()) {
            $_SESSION['message'] = "Template updated successfully";
        } else {
            $_SESSION['message'] = "Error updating template: " . $stmt->error;
        }
        $stmt->close();
    }
    
    header("Location: " . $_SERVER['PHP_SELF']);
    exit;
}

// Fetch client data
$sql = "SELECT `client_id`, `client_name`, `company_name`, `country`, `currency`, `template` 
        FROM `client_master` 
        WHERE 1 
        ORDER BY client_name ASC";

$stmt = $mysqli->prepare($sql);
if (!$stmt) die("Prepare failed: " . $mysqli->error);

$stmt->execute();
$result = $stmt->get_result();
$clients = $result->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Check if template column exists, if not, add it
$check_column_sql = "SHOW COLUMNS FROM client_master LIKE 'template'";
$column_result = $mysqli->query($check_column_sql);
if ($column_result->num_rows == 0) {
    $add_column_sql = "ALTER TABLE client_master ADD COLUMN template TEXT NULL AFTER currency";
    $mysqli->query($add_column_sql);
}
require 'header.php';
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Client Management</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/dataTables.bootstrap5.min.css">
    <style>
        .template-cell { 
            max-width: 200px; 
            overflow: hidden; 
            text-overflow: ellipsis; 
            white-space: nowrap; 
            cursor: pointer; 
        }
        .template-cell:hover { 
            overflow: visible; 
            white-space: normal; 
            background: #f8f9fa; 
        }
        /* .card-header { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; } */
        .card-header { background-color: #e9ecef !important; color: #212529 !important; }
        .btn-template { background: #20c997; border-color: #20c997; color: white; }
        .btn-template:hover { background: #199d7a; border-color: #199d7a; }
    </style>
</head>
<body>
    <div class="container-fluid mt-4">
        <div class="card">
            <div class="card-header">
                <h3 class="mb-0"><i class="bi bi-people-fill"></i> Client Management</h3>
            </div>
            <div class="card-body">
                <?php if (isset($_SESSION['message'])): ?>
                    <div class="alert alert-info alert-dismissible fade show" role="alert">
                        <?= htmlspecialchars($_SESSION['message']) ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                    </div>
                    <?php unset($_SESSION['message']); ?>
                <?php endif; ?>

                <div class="table-responsive">
                    <table class="table table-striped table-bordered" id="clientsTable">
                        <!-- <thead class="table-light"> -->
                            <thead style="background-color: #e9ecef !important; color: #212529 !important;">
                            <tr>
                                <th>S.No</th>
                                <th>Client Name</th>
                                <th>Company Name</th>
                                <th>Country</th>
                                <th>Currency</th>
                                <th>Template</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (!empty($clients)): ?>
                                <?php foreach($clients as $index => $client): ?>
                                <tr>
                                    <td><?= $index + 1 ?></td>
                                    <td><?= htmlspecialchars($client['client_name']) ?></td>
                                    <td><?= htmlspecialchars($client['company_name'] ?? 'N/A') ?></td>
                                    <td><?= htmlspecialchars($client['country'] ?? 'N/A') ?></td>
                                    <td><?= htmlspecialchars($client['currency'] ?? 'N/A') ?></td>
                                    <td class="template-cell" 
                                        title="<?= htmlspecialchars($client['template'] ?? 'No template set') ?>"
                                        onclick="openTemplateModal(<?= $client['client_id'] ?>, '<?= htmlspecialchars(addslashes($client['template'] ?? '')) ?>')">
                                        <?= !empty($client['template']) ? 
                                            (strlen($client['template']) > 50 ? 
                                                substr(htmlspecialchars($client['template']), 0, 50) . '...' : 
                                                htmlspecialchars($client['template'])) 
                                            : '<span class="text-muted">No template</span>' ?>
                                    </td>
                                    <td>
                                        <div class="btn-group btn-group-sm" role="group">
                                            <button type="button" class="btn btn-template" 
                                                    onclick="openTemplateModal(<?= $client['client_id'] ?>, '<?= htmlspecialchars(addslashes($client['template'] ?? '')) ?>')"
                                                    title="Edit Template">
                                                <i class="bi bi-file-earmark-text"></i> Template
                                            </button>
                                            <!--a href="edit_client.php?id=<?= $client['client_id'] ?>" class="btn btn-warning" title="Edit Client">
                                                <i class="bi bi-pencil"></i>
                                            </a-->
                                        </div>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="7" class="text-center text-muted">No clients found</td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <!-- Template Edit Modal -->
    <div class="modal fade" id="templateModal" tabindex="-1" aria-labelledby="templateModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <form method="POST" action="<?= $_SERVER['PHP_SELF'] ?>">
                    <div class="modal-header">
                        <h5 class="modal-title" id="templateModalLabel">Edit Client Template</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <input type="hidden" name="client_id" id="modal_client_id">
                        <div class="mb-3">
                            <label for="client_info" class="form-label">Client Information</label>
                            <div id="client_info" class="form-control bg-light" style="min-height: 60px;">
                                <strong id="modal_client_name"></strong><br>
                                <small id="modal_company_name" class="text-muted"></small>
                            </div>
                        </div>
                        <div class="mb-3">
                            <label for="template_content" class="form-label">Template Content</label>
                            <textarea class="form-control" id="template_content" name="template" rows="8" 
                                      placeholder="Enter your template content here..."></textarea>
                            <div class="form-text">
                                You can use variables like {client_name}, {company_name}, {country}, {currency} in your template.
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" name="update_template" class="btn btn-primary">
                            <i class="bi bi-check-circle"></i> Save Template
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.6/js/dataTables.bootstrap5.min.js"></script>

    <script>
    $(document).ready(function() {
        // Initialize DataTable
        $('#clientsTable').DataTable({
            pageLength: 25,
            order: [[1, 'asc']], // Sort by client name
            responsive: true
        });
    });

    function openTemplateModal(clientId, currentTemplate) {
        // Get client details from the table row
        const row = document.querySelector(`tr:has(button[onclick*="openTemplateModal(${clientId}"])`);
        if (row) {
            const cells = row.cells;
            const clientName = cells[1].textContent;
            const companyName = cells[2].textContent;
            const country = cells[3].textContent;
            const currency = cells[4].textContent;
            
            // Set modal values
            document.getElementById('modal_client_id').value = clientId;
            document.getElementById('modal_client_name').textContent = `Client: ${clientName}`;
            document.getElementById('modal_company_name').textContent = `Company: ${companyName} | Country: ${country} | Currency: ${currency}`;
            
            // Clean and set template content
            const cleanTemplate = currentTemplate ? currentTemplate.replace(/\\'/g, "'").replace(/\\"/g, '"') : '';
            document.getElementById('template_content').value = cleanTemplate;
        }
        
        // Show the modal
        const modal = new bootstrap.Modal(document.getElementById('templateModal'));
        modal.show();
    }

    // Quick template functions
    function insertVariable(variable) {
        const textarea = document.getElementById('template_content');
        const start = textarea.selectionStart;
        const end = textarea.selectionEnd;
        const text = textarea.value;
        const newText = text.substring(0, start) + variable + text.substring(end);
        textarea.value = newText;
        textarea.focus();
        textarea.setSelectionRange(start + variable.length, start + variable.length);
    }
    </script>
</body>
</html>