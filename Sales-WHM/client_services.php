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
  $base = 'client_services.php';
  if (!empty($params)) $base .= '?' . http_build_query($params);
  header("Location: $base");
  exit;
}

// Get all active clients for dropdown
function getClients($mysqli) {
  $clients = [];
  $sql = "SELECT client_id, client_name, country, currency
      FROM client_master
      WHERE status = 'Active'
      ORDER BY client_name";
  $result = $mysqli->query($sql);
 
  while($row = $result->fetch_assoc()) {
    $clients[] = $row;
  }
  return $clients;
}

// Get services for a specific client
function getClientServices($mysqli, $client_id) {
  $services = [];
  $sql = "SELECT service_name, service_category
      FROM client_services
      WHERE client_id = ? AND is_active = TRUE
      ORDER BY service_category, service_name";
  $stmt = $mysqli->prepare($sql);
  $stmt->bind_param("i", $client_id);
  $stmt->execute();
  $result = $stmt->get_result();
 
  while($row = $result->fetch_assoc()) {
    $services[] = $row['service_name'];
  }
  $stmt->close();
  return $services;
}

// ⭐ MODIFIED: Define all available services with categories (Now fetches dynamically)
function getAllServices($mysqli) {
    $services_by_category = [];
    $sql = "SELECT service_name, category
            FROM services_master
            WHERE is_active = TRUE
            ORDER BY category, service_name";
    
    $result = $mysqli->query($sql);
    
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $category = $row['category'];
            $service_name = $row['service_name'];
            
            // Group services by category
            if (!isset($services_by_category[$category])) {
                $services_by_category[$category] = [];
            }
            $services_by_category[$category][] = $service_name;
        }
    }
    
    return $services_by_category;
}

// Handle POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $action = $_POST['action'] ?? '';
 
  if ($action === 'save_services') {
    $client_id = (int)($_POST['client_id'] ?? 0);
    $client_name = trim($_POST['client_name'] ?? '');
    $selected_services = $_POST['services'] ?? [];
   
    // Validation
    if ($client_id === 0 || empty($client_name)) {
      redirect_with(['msg' => 'Please select a client.', 'type' => 'danger']);
    }
   
    if (empty($selected_services)) {
      redirect_with(['msg' => 'Please select at least one service.', 'type' => 'danger']);
    }
   
    // Begin transaction
    $mysqli->begin_transaction();
   
    try {
      // Deactivate all existing services for this client
      $deactivate_stmt = $mysqli->prepare("UPDATE client_services SET is_active = FALSE WHERE client_id = ?");
      $deactivate_stmt->bind_param("i", $client_id);
      $deactivate_stmt->execute();
      $deactivate_stmt->close();
     
      // ⭐ MODIFIED: Fetch dynamic services
      $all_services = getAllServices($mysqli);
      // Insert or update selected services
      $insert_stmt = $mysqli->prepare("
        INSERT INTO client_services (client_id, client_name, service_name, service_category, is_active)
        VALUES (?, ?, ?, ?, TRUE)
        ON DUPLICATE KEY UPDATE is_active = TRUE, updated_date = NOW()
      ");
     
      
      $services_added = 0;
     
      foreach ($selected_services as $service_name) {
        // Find the category for this service
        $category = '';
        foreach ($all_services as $cat => $services) {
          if (in_array($service_name, $services)) {
            $category = $cat;
            break;
          }
        }
       
        $insert_stmt->bind_param("isss", $client_id, $client_name, $service_name, $category);
        if ($insert_stmt->execute()) {
          $services_added++;
        }
      }
     
      $insert_stmt->close();
     
      // Commit transaction
      $mysqli->commit();
     
      redirect_with([
        'msg' => "Successfully saved $services_added services for $client_name",
        'type' => 'success',
        'client_id' => $client_id
      ]);
     
    } catch (Exception $e) {
      // Rollback transaction on error
      $mysqli->rollback();
      redirect_with(['msg' => 'Error saving services: ' . $e->getMessage(), 'type' => 'danger']);
    }
  }
 
  if ($action === 'delete_service') {
    $service_id = (int)($_POST['service_id'] ?? 0);
   
    if ($service_id > 0) {
      // Get service details for message
      $res = $mysqli->prepare("SELECT client_name, service_name FROM client_services WHERE service_id = ?");
      $res->bind_param("i", $service_id);
      $res->execute();
      $res->bind_result($client_name, $service_name);
      $res->fetch();
      $res->close();
     
      // Delete service
      $del = $mysqli->prepare("DELETE FROM client_services WHERE service_id = ?");
      $del->bind_param("i", $service_id);
     
      if ($del->execute()) {
        redirect_with(['msg' => "Service '$service_name' deleted for client '$client_name'", 'type' => 'warning']);
      } else {
        redirect_with(['msg' => 'Error deleting service: ' . $del->error, 'type' => 'danger']);
      }
      $del->close();
    }
  }
}

// Get selected client ID from URL or form
$selected_client_id = (int)($_GET['client_id'] ?? 0);
$client_services = [];
$client_name = '';

if ($selected_client_id > 0) {
  // Get client name
  $stmt = $mysqli->prepare("SELECT client_name FROM client_master WHERE client_id = ?");
  $stmt->bind_param("i", $selected_client_id);
  $stmt->execute();
  $stmt->bind_result($client_name);
  $stmt->fetch();
  $stmt->close();
 
  // Get client's current services
  $client_services = getClientServices($mysqli, $selected_client_id);
}

// Get all clients for dropdown
$clients = getClients($mysqli);

// ⭐ MODIFIED: Get all services dynamically
$all_services = getAllServices($mysqli);

// Get search parameters
$q = trim($_GET['q'] ?? '');
$client_filter = (int)($_GET['client_filter'] ?? 0);

// Build query for services list
// Build query for services list
$sql = "SELECT
      cs.service_id,
      cs.client_id,
      cs.client_name,
      cs.service_name,
      cs.service_category,
      cs.is_active,
      cs.created_date,
      cs.updated_date,
      cm.country,
      sm.service_amount
    FROM client_services cs
    JOIN client_master cm ON cs.client_id = cm.client_id
    LEFT JOIN services_master sm ON cs.service_name = sm.service_name
    WHERE cs.is_active = TRUE";
   
$params = [];
$types = '';

if ($q !== '') {
  $sql .= " AND (cs.client_name LIKE ? OR cs.service_name LIKE ? OR cs.service_category LIKE ?)";
  $like = "%$q%";
  $params[] = $like;
  $params[] = $like;
  $params[] = $like;
  $types .= 'sss';
}

if ($client_filter > 0) {
  $sql .= " AND cs.client_id = ?";
  $params[] = $client_filter;
  $types .= 'i';
}

$sql .= " ORDER BY cs.client_name, cs.service_category, cs.service_name";

// Execute query
if (!empty($types)) {
  $stmt = $mysqli->prepare($sql);
  $stmt->bind_param($types, ...$params);
  $stmt->execute();
  $services_list = $stmt->get_result();
} else {
  $services_list = $mysqli->query($sql);
}

// If query fails, show empty result
if (!$services_list) {
  $services_list = false;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Client Services Management</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
  <style>
    body {
      background-color: #f8f9fa;
      font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
    }
    .main-content {
      padding: 20px;
      max-width: 1800px;
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
    .form-section {
      background: #f8f9fa;
      padding: 15px;
      border-radius: 5px;
      margin-bottom: 20px;
    }
    .service-category {
      background: #e9ecef;
      padding: 10px 15px;
      margin: 10px 0;
      border-radius: 5px;
      font-weight: bold;
      color: #495057;
    }
    .service-checkboxes {
      display: grid;
      grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
      gap: 10px;
      margin-bottom: 15px;
    }
    .service-checkbox {
      padding: 8px 12px;
      border: 1px solid #dee2e6;
      border-radius: 4px;
      background: white;
      transition: all 0.2s;
    }
    .service-checkbox:hover {
      background: #f8f9fa;
      border-color: #007bff;
    }
    .service-checkbox.checked {
      background: #d4edda;
      border-color: #28a745;
    }
    .table-responsive {
      overflow-x: auto;
    }
    .client-info-box {
      background: #d1ecf1;
      padding: 15px;
      border-radius: 5px;
      margin-bottom: 20px;
    }
    .category-badge {
      font-size: 0.7rem;
      margin-left: 5px;
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

        <div class="card mb-4">
      <div class="card-header bg-primary text-white">
        <h5 class="mb-0"><i class="fas fa-user me-2"></i>Select Client</h5>
        

[Image of Client Services Management Diagram]

      </div>
      <div class="card-body">
        <form method="get" class="row g-3 align-items-center">
          <div class="col-md-6">
            <label for="client_id" class="form-label">Choose Client</label>
            <select class="form-control" id="client_id" name="client_id" required onchange="this.form.submit()">
              <option value="">Select a Client</option>
              <?php foreach($clients as $client): ?>
                <option value="<?= $client['client_id'] ?>"
                  <?= $selected_client_id == $client['client_id'] ? 'selected' : '' ?>>
                  <?= h($client['client_name']) ?> (<?= h($client['country']) ?>)
                </option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-md-6">
            <div class="mt-4">
              <?php if($selected_client_id > 0): ?>
                <div class="client-info-box">
                  <h6 class="mb-2">Selected Client: <strong><?= h($client_name) ?></strong></h6>
                  <small class="text-muted">Client ID: <?= $selected_client_id ?></small>
                  <?php if(!empty($client_services)): ?>
                    <div class="mt-2">
                      <strong>Current Services:</strong>
                      <?= count($client_services) ?> service(s) configured
                    </div>
                  <?php endif; ?>
                </div>
              <?php else: ?>
                <p class="text-muted">Please select a client to manage their services.</p>
              <?php endif; ?>
            </div>
          </div>
        </form>
      </div>
    </div>

    <?php if($selected_client_id > 0): ?>

        <div class="card mb-4">
      <div class="card-header bg-success text-white">
        <h5 class="mb-0"><i class="fas fa-cogs me-2"></i>Select Services for <?= h($client_name) ?></h5>
      </div>
      <div class="card-body">
        <form method="post" id="servicesForm">
          <input type="hidden" name="action" value="save_services">
          <input type="hidden" name="client_id" value="<?= $selected_client_id ?>">
          <input type="hidden" name="client_name" value="<?= h($client_name) ?>">

          <?php foreach($all_services as $category => $services): ?>
          <div class="form-section">
            <div class="service-category">
              <i class="fas fa-folder me-2"></i><?= h($category) ?>
              <span class="badge bg-secondary category-badge"><?= count($services) ?> services</span>
            </div>
            <div class="service-checkboxes">
              <?php foreach($services as $service):
                $is_checked = in_array($service, $client_services);
              ?>
              <div class="form-check service-checkbox <?= $is_checked ? 'checked' : '' ?>">
                <input class="form-check-input" type="checkbox" name="services[]"
                   value="<?= h($service) ?>" id="service_<?= md5($service) ?>"
                   <?= $is_checked ? 'checked' : '' ?>>
                <label class="form-check-label w-100" for="service_<?= md5($service) ?>">
                  <?= h($service) ?>
                </label>
              </div>
              <?php endforeach; ?>
            </div>
          </div>
          <?php endforeach; ?>

          <div class="d-flex gap-2 mt-4">
            <button type="submit" class="btn btn-success btn-lg">
              <i class="fas fa-save me-2"></i>Save Services for <?= h($client_name) ?>
            </button>
            <button type="button" class="btn btn-outline-secondary" onclick="selectAllServices()">
              <i class="fas fa-check-square me-2"></i>Select All
            </button>
            <button type="button" class="btn btn-outline-secondary" onclick="deselectAllServices()">
              <i class="fas fa-times-circle me-2"></i>Deselect All
            </button>
          </div>
        </form>
      </div>
    </div>

    <?php endif; ?>

        <div class="card">
      <div class="card-header bg-primary text-white d-flex justify-content-between align-items-center">
        <h5 class="mb-0"><i class="fas fa-list me-2"></i>All Client Services</h5>
        <div>
          <span class="badge bg-light text-dark">
            <?= $services_list && $services_list->num_rows > 0 ? $services_list->num_rows : '0' ?> active services
          </span>
        </div>
      </div>
      <div class="card-body">
                <div class="row mb-3">
          <div class="col-md-4">
            <input type="text" name="q" class="form-control form-control-sm"
               placeholder="Search clients, services..." value="<?= h($q) ?>"
               onchange="this.form.submit()" form="searchForm">
          </div>
          <div class="col-md-4">
            <select name="client_filter" class="form-select form-select-sm" onchange="this.form.submit()" form="searchForm">
              <option value="0">All Clients</option>
              <?php foreach($clients as $client): ?>
                <option value="<?= $client['client_id'] ?>" <?= $client_filter == $client['client_id'] ? 'selected' : '' ?>>
                  <?= h($client['client_name']) ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-md-4">
            <form method="get" id="searchForm" class="d-inline">
              <button type="submit" class="btn btn-primary btn-sm me-2">
                <i class="fas fa-search me-1"></i> Search
              </button>
              <a href="client_services.php" class="btn btn-secondary btn-sm">
                <i class="fas fa-times me-1"></i> Clear
              </a>
            </form>
          </div>
        </div>

        <div class="table-responsive">
          <table class="table table-hover align-middle mb-0">
            <thead class="table-light">
              <tr>
                <th width="30">ID</th>
                <th>Client Name</th>
                <th>Service Name</th>
                <th>Service Amount</th>
                <th>Category</th>
                <th>Country</th>
                <th>Status</th>
                <th>Created Date</th>
                <th width="100">Actions</th>
              </tr>
            </thead>
            <tbody>
              <?php if($services_list && $services_list->num_rows > 0): $i=0;?>
                <?php while($row = $services_list->fetch_assoc()): $i=$i+1;?>
                <tr>
                  <td class="fw-bold"><?= $i ?></td>
                  <td>
                    <div class="fw-semibold"><?= h($row['client_name']) ?></div>
                    <small class="text-muted">ID: <?= h($row['client_id']) ?></small>
                  </td>
                  <td><?= h($row['service_name']) ?></td>
                  <td>
                      <span class="fw-bold text-dark">
                          <?= isset($row['service_amount']) ? number_format((float)$row['service_amount'], 2) : '0.00' ?>
                      </span>
                    </td>
                  <td>
                    <span class="badge bg-secondary"><?= h($row['service_category']) ?></span>
                  </td>
                  <td><?= h($row['country']) ?: '—' ?></td>
                  <td>
                    <?php if($row['is_active']): ?>
                      <span class="badge bg-success">Active</span>
                    <?php else: ?>
                      <span class="badge bg-danger">Inactive</span>
                    <?php endif; ?>
                  </td>
                  <td><?= date('d-m-Y', strtotime($row['created_date'])) ?></td>
                  <td class="text-center">
                    <form method="post" style="display:inline;"
                       onsubmit="return confirm('Are you sure you want to delete this service for <?= h($row['client_name']) ?>?')">
                      <input type="hidden" name="action" value="delete_service">
                      <input type="hidden" name="service_id" value="<?= (int)$row['service_id'] ?>">
                      <button type="submit" class="btn btn-danger btn-sm" title="Delete Service">
                        <i class="fas fa-trash"></i>
                      </button>
                    </form>
                  </td>
                </tr>
                <?php endwhile; ?>
              <?php else: ?>
                <tr>
                  <td colspan="8" class="text-center py-5">
                    <div class="text-muted">
                      <i class="fas fa-cogs fa-3x mb-3"></i>
                      <h5>No services found</h5>
                      <p class="mb-0">Select a client above to start adding services.</p>
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

      // Update checkbox styling on change
      const checkboxes = document.querySelectorAll('input[type="checkbox"][name="services[]"]');
      checkboxes.forEach(checkbox => {
        checkbox.addEventListener('change', function() {
          const parent = this.closest('.service-checkbox');
          if (this.checked) {
            parent.classList.add('checked');
          } else {
            parent.classList.remove('checked');
          }
        });
      });
    });

    function selectAllServices() {
      const checkboxes = document.querySelectorAll('input[type="checkbox"][name="services[]"]');
      checkboxes.forEach(checkbox => {
        checkbox.checked = true;
        checkbox.closest('.service-checkbox').classList.add('checked');
      });
    }

    function deselectAllServices() {
      const checkboxes = document.querySelectorAll('input[type="checkbox"][name="services[]"]');
      checkboxes.forEach(checkbox => {
        checkbox.checked = false;
        checkbox.closest('.service-checkbox').classList.remove('checked');
      });
    }

    // Auto-submit client selection form when client is selected
    document.getElementById('client_id')?.addEventListener('change', function() {
      if (this.value) {
        this.form.submit();
      }
    });
  </script>
</body>
</html>