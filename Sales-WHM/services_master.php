<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
require 'db.php';
if (session_status() === PHP_SESSION_NONE) { 
    session_start(); 
}

// Helper functions
function h($s) { 
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); 
}

function redirect_with($params = []) {
    $base = 'services_master.php';
    if (!empty($params)) $base .= '?' . http_build_query($params);
    header("Location: $base");
    exit;
}

// Get all services from services master
function getAllServices($mysqli) {
    $services = [];
    $sql = "SELECT service_id, category, service_name, description, is_active, created_date 
            FROM services_master 
            ORDER BY category, service_name";
    $result = $mysqli->query($sql);
    
    while($row = $result->fetch_assoc()) {
        $services[] = $row;
    }
    return $services;
}

// Get service by ID
function getServiceById($mysqli, $service_id) {
    $sql = "SELECT service_id, category, service_name, description, is_active 
            FROM services_master 
            WHERE service_id = ?";
    $stmt = $mysqli->prepare($sql);
    $stmt->bind_param("i", $service_id);
    $stmt->execute();
    $result = $stmt->get_result();
    return $result->fetch_assoc();
}

// Handle POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'add_service') {
        $category = trim($_POST['category'] ?? '');
        $service_name = trim($_POST['service_name'] ?? '');
        $service_amount = $_POST['service_amount'] ?? '0.00';
        $description = trim($_POST['description'] ?? '');
        
        if (empty($category) || empty($service_name)) {
            redirect_with(['msg' => 'Category and Service Name are required.', 'type' => 'danger']);
        }
        
        // Check if service already exists
        $check_stmt = $mysqli->prepare("SELECT service_id FROM services_master WHERE category = ? AND service_name = ?");
        $check_stmt->bind_param("ss", $category, $service_name);
        $check_stmt->execute();
        $check_stmt->store_result();
        
        if ($check_stmt->num_rows > 0) {
            $check_stmt->close();
            redirect_with(['msg' => 'Service with this name already exists in the selected category.', 'type' => 'danger']);
        }
        $check_stmt->close();
        
        $stmt = $mysqli->prepare("INSERT INTO services_master (category, service_name, service_amount, description) VALUES (?, ?, ?,?)");
        $stmt->bind_param("ssss", $category, $service_name, $service_amount, $description);
        
        if ($stmt->execute()) {
            redirect_with(['msg' => "Service '$service_name' added successfully", 'type' => 'success']);
        } else {
            redirect_with(['msg' => 'Error adding service: ' . $stmt->error, 'type' => 'danger']);
        }
        $stmt->close();
    }
    
    if ($action === 'update_service') {
        $service_id = (int)($_POST['service_id'] ?? 0);
        $category = trim($_POST['category'] ?? '');
        $service_name = trim($_POST['service_name'] ?? '');
        $service_amount = $_POST['service_amount'] ?? '0.00'; 
        $description = trim($_POST['description'] ?? '');
        
        if ($service_id === 0 || empty($category) || empty($service_name)) {
            redirect_with(['msg' => 'Service ID, Category and Service Name are required.', 'type' => 'danger']);
        }
        
        // Check if service already exists (excluding current service)
        $check_stmt = $mysqli->prepare("SELECT service_id FROM services_master WHERE category = ? AND service_name = ? AND service_id != ?");
        $check_stmt->bind_param("ssi", $category, $service_name, $service_id);
        $check_stmt->execute();
        $check_stmt->store_result();
        
        if ($check_stmt->num_rows > 0) {
            $check_stmt->close();
            redirect_with(['msg' => 'Service with this name already exists in the selected category.', 'type' => 'danger']);
        }
        $check_stmt->close();
        
        $stmt = $mysqli->prepare("UPDATE services_master SET category = ?, service_name = ?, service_amount = ?, description = ?, updated_date = NOW() WHERE service_id = ?");
        $stmt->bind_param("ssssi", $category, $service_name,$service_amount, $description, $service_id);
        
        if ($stmt->execute()) {
            redirect_with(['msg' => "Service updated successfully", 'type' => 'success']);
        } else {
            redirect_with(['msg' => 'Error updating service: ' . $stmt->error, 'type' => 'danger']);
        }
        $stmt->close();
    }
    
    if ($action === 'delete_service') {
        $service_id = (int)($_POST['service_id'] ?? 0);
        
        if ($service_id > 0) {
            // Check if service is used in client_services
            $check_stmt = $mysqli->prepare("SELECT COUNT(*) FROM client_services WHERE service_id = ? AND is_active = TRUE");
            $check_stmt->bind_param("i", $service_id);
            $check_stmt->execute();
            $check_stmt->bind_result($usage_count);
            $check_stmt->fetch();
            $check_stmt->close();
            
            if ($usage_count > 0) {
                redirect_with(['msg' => 'Cannot delete service. It is currently assigned to clients.', 'type' => 'danger']);
            } else {
                $del = $mysqli->prepare("DELETE FROM services_master WHERE service_id = ?");
                $del->bind_param("i", $service_id);
                
                if ($del->execute()) {
                    redirect_with(['msg' => "Service deleted successfully", 'type' => 'warning']);
                } else {
                    redirect_with(['msg' => 'Error deleting service: ' . $del->error, 'type' => 'danger']);
                }
                $del->close();
            }
        }
    }
    
    if ($action === 'toggle_service_status') {
        $service_id = (int)($_POST['service_id'] ?? 0);
        
        if ($service_id > 0) {
            // Get current status
            $current = $mysqli->prepare("SELECT is_active FROM services_master WHERE service_id = ?");
            $current->bind_param("i", $service_id);
            $current->execute();
            $current->bind_result($is_active);
            $current->fetch();
            $current->close();
            
            $new_status = $is_active ? 0 : 1;
            
            $stmt = $mysqli->prepare("UPDATE services_master SET is_active = ?, updated_date = NOW() WHERE service_id = ?");
            $stmt->bind_param("ii", $new_status, $service_id);
            
            if ($stmt->execute()) {
                $status_text = $new_status ? 'activated' : 'deactivated';
                redirect_with(['msg' => "Service $status_text successfully", 'type' => 'success']);
            } else {
                redirect_with(['msg' => 'Error updating service status: ' . $stmt->error, 'type' => 'danger']);
            }
            $stmt->close();
        }
    }
}

// Get search parameters
$q = trim($_GET['q'] ?? '');
$category_filter = $_GET['category_filter'] ?? '';
$status_filter = $_GET['status_filter'] ?? '';

// Build query for services master list
$sql = "SELECT service_id, category, service_name,service_amount,description, is_active, created_date, updated_date 
        FROM services_master 
        WHERE 1=1";
        
$params = [];
$types = '';

if ($q !== '') {
    $sql .= " AND (service_name LIKE ? OR description LIKE ? OR category LIKE ?)";
    $like = "%$q%";
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
    $types .= 'sss';
}

if ($category_filter !== '') {
    $sql .= " AND category = ?";
    $params[] = $category_filter;
    $types .= 's';
}

if ($status_filter !== '') {
    $sql .= " AND is_active = ?";
    $params[] = $status_filter;
    $types .= 'i';
}

$sql .= " ORDER BY category, service_name";

// Execute query
if (!empty($types)) {
    $stmt = $mysqli->prepare($sql);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $services_master_list = $stmt->get_result();
} else {
    $services_master_list = $mysqli->query($sql);
}

// If query fails, show empty result
if (!$services_master_list) {
    $services_master_list = false;
}

// Get categories for filter
$categories = ['XML Types', 'HTML', 'Extra Services'];
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Services Master Management</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
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
            font-size: 0.85rem;
            white-space: nowrap;
        }
        .table td {
            vertical-align: middle;
            font-size: 0.82rem;
        }
        .card {
            box-shadow: 0 0.125rem 0.25rem rgba(0, 0, 0, 0.075);
            border: 1px solid rgba(0, 0, 0, 0.125);
        }
        .btn-group-sm > .btn {
            padding: 0.25rem 0.5rem;
        }
        .table-responsive {
            overflow-x: auto;
        }
        .category-badge {
            font-size: 0.75rem;
        }
        .stats-card {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border-radius: 10px;
            padding: 20px;
            margin-bottom: 20px;
        }
        .stats-number {
            font-size: 2.5rem;
            font-weight: bold;
            margin-bottom: 0;
        }
        .stats-label {
            font-size: 0.9rem;
            opacity: 0.9;
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

        <!-- Page Header -->
        <div class="d-flex justify-content-between align-items-center mb-4">
            <div>
                <h2><i class="fas fa-cogs me-2"></i>Services Master Management</h2>
                <p class="text-muted mb-0">Manage all available services and their categories</p>
            </div>
            <div>
                <a href="client_services.php" class="btn btn-outline-primary me-2">
                    <i class="fas fa-arrow-left me-1"></i> Back to Client Services
                </a>
                <button type="button" class="btn btn-success" data-bs-toggle="modal" data-bs-target="#addServiceModal">
                    <i class="fas fa-plus me-1"></i> Add New Service
                </button>
            </div>
        </div>

        <!-- Statistics Cards -->
        <div class="row mb-4">
            <div class="col-md-3">
                <div class="stats-card">
                    <div class="stats-number">
                        <?php
                        $total_services = $mysqli->query("SELECT COUNT(*) FROM services_master")->fetch_row()[0];
                        echo $total_services;
                        ?>
                    </div>
                    <div class="stats-label">Total Services</div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="stats-card" style="background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%);">
                    <div class="stats-number">
                        <?php
                        $active_services = $mysqli->query("SELECT COUNT(*) FROM services_master WHERE is_active = TRUE")->fetch_row()[0];
                        echo $active_services;
                        ?>
                    </div>
                    <div class="stats-label">Active Services</div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="stats-card" style="background: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%);">
                    <div class="stats-number">
                        <?php
                        $xml_services = $mysqli->query("SELECT COUNT(*) FROM services_master WHERE category = 'XML Types'")->fetch_row()[0];
                        echo $xml_services;
                        ?>
                    </div>
                    <div class="stats-label">XML Type Services</div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="stats-card" style="background: linear-gradient(135deg, #43e97b 0%, #38f9d7 100%);">
                    <div class="stats-number">
                        <?php
                        $used_services = $mysqli->query("SELECT COUNT(DISTINCT service_id) FROM client_services WHERE is_active = TRUE")->fetch_row()[0];
                        echo $used_services;
                        ?>
                    </div>
                    <div class="stats-label">Services in Use</div>
                </div>
            </div>
        </div>

        <!-- Search and Filter Card -->
        <div class="card mb-4">
            <div class="card-header bg-light">
                <h5 class="mb-0"><i class="fas fa-search me-2"></i>Search & Filter Services</h5>
            </div>
            <div class="card-body">
                <form method="get" class="row g-3 align-items-center">
                    <div class="col-md-3">
                        <input type="text" name="q" class="form-control form-control-sm" 
                               placeholder="Search services..." value="<?= h($q) ?>">
                    </div>
                    <div class="col-md-2">
                        <select name="category_filter" class="form-select form-select-sm">
                            <option value="">All Categories</option>
                            <?php foreach($categories as $category): ?>
                                <option value="<?= h($category) ?>" <?= $category_filter === $category ? 'selected' : '' ?>>
                                    <?= h($category) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <select name="status_filter" class="form-select form-select-sm">
                            <option value="">All Status</option>
                            <option value="1" <?= $status_filter === '1' ? 'selected' : '' ?>>Active</option>
                            <option value="0" <?= $status_filter === '0' ? 'selected' : '' ?>>Inactive</option>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <button type="submit" class="btn btn-primary btn-sm w-100">
                            <i class="fas fa-search me-1"></i> Search
                        </button>
                    </div>
                    <div class="col-md-3">
                        <a href="services_master.php" class="btn btn-secondary btn-sm">
                            <i class="fas fa-times me-1"></i> Clear Filters
                        </a>
                    </div>
                </form>
            </div>
        </div>

        <!-- Services Master Table -->
        <div class="card">
            <div class="card-header bg-info text-white d-flex justify-content-between align-items-center">
                <h5 class="mb-0"><i class="fas fa-list me-2"></i>All Services</h5>
                <div>
                    <span class="badge bg-light text-dark">
                        <?= $services_master_list && $services_master_list->num_rows > 0 ? $services_master_list->num_rows : '0' ?> services found
                    </span>
                </div>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th width="50">ID</th>
                                <th width="150">Category</th>
                                <th>Service Name</th>
                                <th width="120">Amount</th>
                                <th>Description</th>
                                <th width="100">Status</th>
                                <th width="120">Created Date</th>
                                <th width="150">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if($services_master_list && $services_master_list->num_rows > 0): ?>
                                <?php while($row = $services_master_list->fetch_assoc()): ?>
                                <tr>
                                    <td class="fw-bold"><?= h($row['service_id']) ?></td>
                                    <td>
                                        <span class="badge bg-primary category-badge"><?= h($row['category']) ?></span>
                                    </td>
                                    <td>
                                        <div class="fw-semibold"><?= h($row['service_name']) ?></div>
                                    </td>
                                    <td>
                                        <span class="fw-bold"><?= number_format($row['service_amount'], 2) ?></span>
                                    </td>
                                    <td>
                                        <?php if(!empty($row['description'])): ?>
                                            <span title="<?= h($row['description']) ?>">
                                                <?= strlen($row['description']) > 50 ? substr(h($row['description']), 0, 50) . '...' : h($row['description']) ?>
                                            </span>
                                        <?php else: ?>
                                            <span class="text-muted">—</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if($row['is_active']): ?>
                                            <span class="badge bg-success">Active</span>
                                        <?php else: ?>
                                            <span class="badge bg-danger">Inactive</span>
                                        <?php endif; ?>
                                    </td>
                                    <td><?= date('d-m-Y', strtotime($row['created_date'])) ?></td>
                                    <td>
                                        <div class="btn-group btn-group-sm" role="group">
                                            <button type="button" class="btn btn-warning" title="Edit Service" 
                                                    onclick="editService(<?= $row['service_id'] ?>, '<?= h($row['category']) ?>', '<?= h($row['service_name']) ?>','<?= $row['service_amount'] ?>', '<?= h($row['description']) ?>')">
                                                <i class="fas fa-edit"></i>
                                            </button>
                                            <form method="post" style="display:inline;" 
                                                  onsubmit="return confirm('Are you sure you want to <?= $row['is_active'] ? 'deactivate' : 'activate' ?> this service?')">
                                                <input type="hidden" name="action" value="toggle_service_status">
                                                <input type="hidden" name="service_id" value="<?= (int)$row['service_id'] ?>">
                                                <button type="submit" class="btn btn-<?= $row['is_active'] ? 'secondary' : 'success' ?>" 
                                                        title="<?= $row['is_active'] ? 'Deactivate' : 'Activate' ?> Service">
                                                    <i class="fas fa-<?= $row['is_active'] ? 'times' : 'check' ?>"></i>
                                                </button>
                                            </form>
                                            <form method="post" style="display:inline;" 
                                                  onsubmit="return confirm('Are you sure you want to delete this service? This action cannot be undone.')">
                                                <input type="hidden" name="action" value="delete_service">
                                                <input type="hidden" name="service_id" value="<?= (int)$row['service_id'] ?>">
                                                <button type="submit" class="btn btn-danger" title="Delete Service">
                                                    <i class="fas fa-trash"></i>
                                                </button>
                                            </form>
                                        </div>
                                    </td>
                                </tr>
                                <?php endwhile; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="7" class="text-center py-5">
                                        <div class="text-muted">
                                            <i class="fas fa-cogs fa-3x mb-3"></i>
                                            <h5>No services found</h5>
                                            <p class="mb-0">Add new services to get started.</p>
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

    <!-- Add Service Modal -->
    <div class="modal fade" id="addServiceModal" tabindex="-1" aria-labelledby="addServiceModalLabel" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header bg-success text-white">
                    <h5 class="modal-title" id="addServiceModalLabel">
                        <i class="fas fa-plus me-2"></i>Add New Service
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form method="post">
                    <input type="hidden" name="action" value="add_service">
                    <div class="modal-body">
                        <div class="mb-3">
                            <label for="category" class="form-label">Category <span class="text-danger">*</span></label>
                            <select class="form-control" id="category" name="category" required>
                                <option value="">Select Category</option>
                                <option value="XML Types">XML Types</option>
                                <option value="HTML">HTML</option>
                                <option value="Extra Services">Extra Services</option>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label for="service_name" class="form-label">Service Name <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" id="service_name" name="service_name" required>
                        </div>
                        <div class="mb-3">
                            <label for="service_amount" class="form-label">Service Amount</label>
                            <div class="input-group">
                                <span class="input-group-text">₹</span>
                                <input type="number" step="0.01" min="0" class="form-control" id="service_amount" name="service_amount" placeholder="0.00">
                            </div>
                        </div>
                        <div class="mb-3">
                            <label for="description" class="form-label">Description</label>
                            <textarea class="form-control" id="description" name="description" rows="3" placeholder="Optional description of the service"></textarea>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-success">Add Service</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Edit Service Modal -->
    <div class="modal fade" id="editServiceModal" tabindex="-1" aria-labelledby="editServiceModalLabel" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header bg-warning text-dark">
                    <h5 class="modal-title" id="editServiceModalLabel">
                        <i class="fas fa-edit me-2"></i>Edit Service
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form method="post">
                    <input type="hidden" name="action" value="update_service">
                    <input type="hidden" name="service_id" id="edit_service_id">
                    <div class="modal-body">
                        <div class="mb-3">
                            <label for="edit_category" class="form-label">Category <span class="text-danger">*</span></label>
                            <select class="form-control" id="edit_category" name="category" required>
                                <option value="">Select Category</option>
                                <option value="XML Types">XML Types</option>
                                <option value="HTML">HTML</option>
                                <option value="Extra Services">Extra Services</option>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label for="edit_service_name" class="form-label">Service Name <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" id="edit_service_name" name="service_name" required>
                        </div>
                        <div class="mb-3">
                            <label for="edit_service_amount" class="form-label">Service Amount</label>
                            <div class="input-group">
                                <span class="input-group-text">₹</span>
                                <input type="number" step="0.01" min="0" class="form-control" id="edit_service_amount" name="service_amount">
                            </div>
                        </div>
                        <div class="mb-3">
                            <label for="edit_description" class="form-label">Description</label>
                            <textarea class="form-control" id="edit_description" name="description" rows="3" placeholder="Optional description of the service"></textarea>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-warning">Update Service</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // Auto-dismiss alerts
            const alerts = document.querySelectorAll('.alert');
            alerts.forEach(alert => {
                setTimeout(() => {
                    if (alert.isConnected) {
                        const bsAlert = new bootstrap.Alert(alert);
                        bsAlert.close();
                    }
                }, 5000);
            });
        });

        // Add 'amount' to the parameters list here
        function editService(serviceId, category, serviceName, amount, description) {
            document.getElementById('edit_service_id').value = serviceId;
            document.getElementById('edit_category').value = category;
            document.getElementById('edit_service_name').value = serviceName;
            document.getElementById('edit_service_amount').value = amount; // Fix: now using the 'amount' parameter
            document.getElementById('edit_description').value = description || '';
            
            const editModal = new bootstrap.Modal(document.getElementById('editServiceModal'));
            editModal.show();
        }

        // Clear form when add modal is hidden
        const addModal = document.getElementById('addServiceModal');
        if (addModal) {
            addModal.addEventListener('hidden.bs.modal', function () {
                document.getElementById('category').value = '';
                document.getElementById('service_name').value = '';
                document.getElementById('service_amount').value = '';
                document.getElementById('description').value = '';
            });
        }
    </script>
</body>
</html>