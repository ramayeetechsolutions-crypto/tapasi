<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
require 'db.php';

if (session_status() === PHP_SESSION_NONE) {
  session_start();
}

// --- HELPER FUNCTIONS ---
function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

function redirect_with($params = []) {
  $base = 'client_services.php';
  if (!empty($params)) $base .= '?' . http_build_query($params);
  header("Location: $base");
  exit;
}

// Fetch all active clients for dropdowns
function getClients($mysqli) {
  $sql = "SELECT client_id, client_name, country FROM client_master WHERE status = 'Active' ORDER BY client_name";
  return $mysqli->query($sql)->fetch_all(MYSQLI_ASSOC);
}

// Fetch services currently assigned to a specific client
function getClientServices($mysqli, $client_id) {
  $services = [];
  $sql = "SELECT service_name FROM client_services WHERE client_id = ? AND is_active = TRUE";
  $stmt = $mysqli->prepare($sql);
  $stmt->bind_param("i", $client_id);
  $stmt->execute();
  $res = $stmt->get_result();
  while($row = $res->fetch_assoc()) { $services[] = $row['service_name']; }
  return $services;
}

// Fetch all available services from Master, grouped by category
function getAllServicesFromMaster($mysqli) {
    $services_by_category = [];
    $sql = "SELECT service_name, category FROM services_master WHERE is_active = TRUE ORDER BY category, service_name";
    $result = $mysqli->query($sql);
    while ($row = $result->fetch_assoc()) {
        $services_by_category[$row['category']][] = $row['service_name'];
    }
    return $services_by_category;
}

// --- HANDLE POST ACTIONS ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $action = $_POST['action'] ?? '';
 
  // 1. Bulk Save Services for a Client
  if ($action === 'save_services') {
    $client_id = (int)$_POST['client_id'];
    $client_name = $_POST['client_name'];
    $selected_services = $_POST['services'] ?? [];
    
    $mysqli->begin_transaction();
    try {
      // Deactivate current ones to refresh the list
      $mysqli->query("UPDATE client_services SET is_active = FALSE WHERE client_id = $client_id");
      $all_master_services = getAllServicesFromMaster($mysqli);
      
      $stmt = $mysqli->prepare("INSERT INTO client_services (client_id, client_name, service_name, service_category, is_active, status) 
                                VALUES (?, ?, ?, ?, TRUE, 'Active') ON DUPLICATE KEY UPDATE is_active = TRUE");
      
      foreach ($selected_services as $service_name) {
        $category = '';
        foreach ($all_master_services as $cat => $services) { 
            if (in_array($service_name, $services)) { $category = $cat; break; } 
        }
        $stmt->bind_param("isss", $client_id, $client_name, $service_name, $category);
        $stmt->execute();
      }
      $mysqli->commit();
      redirect_with(['msg' => 'Services updated successfully', 'type' => 'success', 'client_id' => $client_id]);
    } catch (Exception $e) {
      $mysqli->rollback();
      redirect_with(['msg' => 'Error: ' . $e->getMessage(), 'type' => 'danger']);
    }
  }

  // 2. Update individual service status/description
  if ($action === 'update_service_details') {
    $service_id = (int)$_POST['service_id'];
    $status = $_POST['status'];
    $description = $_POST['description'];

    $stmt = $mysqli->prepare("UPDATE client_services SET status = ?, description = ? WHERE service_id = ?");
    $stmt->bind_param("ssi", $status, $description, $service_id);
    if ($stmt->execute()) {
        redirect_with(['msg' => 'Service details updated', 'type' => 'success']);
    }
  }
 
  // 3. Delete service link
  if ($action === 'delete_service') {
    $service_id = (int)$_POST['service_id'];
    $mysqli->query("DELETE FROM client_services WHERE service_id = $service_id");
    redirect_with(['msg' => 'Service mapping removed', 'type' => 'warning']);
  }
}

// --- PREPARE DATA FOR FILTERS & DISPLAY ---
$selected_client_id = (int)($_GET['client_id'] ?? 0);
$client_name = '';
if ($selected_client_id > 0) {
  $res = $mysqli->query("SELECT client_name FROM client_master WHERE client_id = $selected_client_id");
  if ($row = $res->fetch_assoc()) $client_name = $row['client_name'];
}

$clients = getClients($mysqli);
$all_master_services = getAllServicesFromMaster($mysqli);
$client_services = $selected_client_id > 0 ? getClientServices($mysqli, $selected_client_id) : [];

// --- DYNAMIC FILTERING LOGIC ---
$q = trim($_GET['q'] ?? '');
$client_filter = (int)($_GET['client_filter'] ?? 0);
$service_filter = trim($_GET['service_filter'] ?? '');
$status_filter = trim($_GET['status_filter'] ?? '');

$sql = "SELECT cs.*, cm.country FROM client_services cs 
        JOIN client_master cm ON cs.client_id = cm.client_id 
        WHERE cs.is_active = TRUE";

if ($q !== '') {
    $sq = $mysqli->real_escape_string($q);
    $sql .= " AND (cs.client_name LIKE '%$sq%' OR cs.service_name LIKE '%$sq%' OR cs.description LIKE '%$sq%')";
}
if ($client_filter > 0) {
    $sql .= " AND cs.client_id = $client_filter";
}
if ($service_filter !== '') {
    $ss = $mysqli->real_escape_string($service_filter);
    $sql .= " AND cs.service_name = '$ss'";
}
if ($status_filter !== '') {
    $st = $mysqli->real_escape_string($status_filter);
    $sql .= " AND cs.status = '$st'";
}

$sql .= " ORDER BY cs.client_name, cs.service_name";
$services_list = $mysqli->query($sql);

// Get unique services for the dropdown filter
$filter_services_res = $mysqli->query("SELECT DISTINCT service_name FROM client_services WHERE is_active = TRUE ORDER BY service_name");
?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>Client Services Management</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
  <style>
    body { background:#f4f7f6; font-family: 'Segoe UI', sans-serif; }
    .main-content { padding: 25px; }
    .table th { background:#6c757d !important; color:white !important; font-size: 0.85rem; border: none; }
    .status-badge { font-size: 0.7rem; font-weight: 700; padding: 5px 10px; }
    .description-text { max-width: 250px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; display: inline-block; cursor: help; }
    .card { border: none; box-shadow: 0 4px 6px rgba(0,0,0,0.05); }
    .category-header { background: #eef2f7; padding: 8px 15px; border-radius: 5px; font-weight: bold; color: #34495e; margin-top: 15px; }
  </style>
</head>
<body>
  <?php require 'header.php'; ?>
  
  <div class="main-content" style="margin-top: 60px;">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h3 class="fw-bold"><i class="fas fa-handshake me-2 text-primary"></i>Add-on Client Info</h3>
    </div>

    <?php if (isset($_GET['msg'])): ?>
        <div class="alert alert-<?= h($_GET['type'] ?? 'info') ?> alert-dismissible fade show" role="alert">
            <?= h($_GET['msg']) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <!-- 1. STEP: SELECT CLIENT -->
    <div class="card mb-4 border-start border-primary border-4">
      <div class="card-body">
        <form method="get" class="row align-items-center">
            <div class="col-md-5">
                <label class="form-label fw-bold">Select Client</label>
                <select class="form-select select2" name="client_id" onchange="this.form.submit()">
                    <option value="">-- Choose a Client --</option>
                    <?php foreach($clients as $c): ?>
                        <option value="<?= $c['client_id'] ?>" <?= $selected_client_id == $c['client_id'] ? 'selected' : '' ?>>
                            <?= h($c['client_name']) ?> (<?= h($c['country']) ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-7">
                <?php if($client_name): ?>
                    <div class="alert alert-light border mb-0 py-2 mt-4">
                        <i class="fas fa-info-circle me-2 text-primary"></i> Currently editing: <strong><?= h($client_name) ?></strong>
                    </div>
                <?php endif; ?>
            </div>
        </form>
      </div>
    </div>

    <!-- 2. STEP: MANAGE SERVICES (Visible when client selected) -->
    <?php if($selected_client_id > 0): ?>
    <div class="card mb-5 border-start border-success border-4 shadow">
      <div class="card-header bg-success text-white fw-bold">
        <i class="fas fa-check-double me-2"></i> Assign Services to <?= h($client_name) ?>
      </div>
      <div class="card-body">
        <form method="post">
          <input type="hidden" name="action" value="save_services">
          <input type="hidden" name="client_id" value="<?= $selected_client_id ?>">
          <input type="hidden" name="client_name" value="<?= h($client_name) ?>">
          
          <div class="row">
          <?php foreach($all_master_services as $cat => $services): ?>
            <div class="col-12"><div class="category-header mb-3 mt-4"><?= h($cat) ?></div></div>
                <?php foreach($services as $s): $checked = in_array($s, $client_services); ?>
                <div class="col-md-3 mb-2">
                    <div class="form-check p-2 border rounded shadow-sm <?= $checked ? 'bg-success-subtle border-success' : 'bg-white' ?>">
                        <input class="form-check-input ms-1" type="checkbox" name="services[]" value="<?= h($s) ?>" <?= $checked ? 'checked' : '' ?>>
                        <label class="form-check-label ms-2 fw-semibold" style="font-size:0.85rem;"><?= h($s) ?></label>
                    </div>
                </div>
                <?php endforeach; ?>
          <?php endforeach; ?>
          </div>
          <hr>
          <div class="mt-3">
              <button type="submit" class="btn btn-success px-4 fw-bold shadow-sm">
                  <i class="fas fa-save me-2"></i>Update Services for <?= h($client_name) ?>
              </button>
              <a href="client_services.php" class="btn btn-outline-secondary px-4 ms-2">Cancel</a>
          </div>
        </form>
      </div>
    </div>
    <?php endif; ?>

    <!-- 3. STEP: FILTER BAR -->
    <div class="card mb-4 bg-blue text-white shadow">
      <div class="card-body">
        <form method="get" class="row g-2">
            <div class="col-md-3">
                <!--label class="small fw-bold">Keyword Search</label-->
                <input type="text" name="q" class="form-control form-control-sm" placeholder="Sort by Client, Service, Desc..." value="<?= h($q) ?>">
            </div>
            <div class="col-md-3">
                <!--label class="small fw-bold">Client</label-->
                <select name="client_filter" class="form-select form-select-sm">
                    <option value="0">Sort by Client</option>
                    <?php foreach($clients as $c): ?>
                        <option value="<?= $c['client_id'] ?>" <?= $client_filter == $c['client_id'] ? 'selected' : '' ?>><?= h($c['client_name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <!--label class="small fw-bold">Service Type</label-->
                <select name="service_filter" class="form-select form-select-sm">
                    <option value="">Sort by Services</option>
                    <?php while($fs = $filter_services_res->fetch_assoc()): ?>
                        <option value="<?= h($fs['service_name']) ?>" <?= $service_filter == $fs['service_name'] ? 'selected' : '' ?>><?= h($fs['service_name']) ?></option>
                    <?php endwhile; ?>
                </select>
            </div>
            <div class="col-md-2">
                <!--label class="small fw-bold">Status</label-->
                <select name="status_filter" class="form-select form-select-sm">
                    <option value="">Sort by Status</option>
                    <option value="Active" <?= $status_filter == 'Active' ? 'selected' : '' ?>>Active</option>
                    <option value="Inactive" <?= $status_filter == 'Inactive' ? 'selected' : '' ?>>Inactive</option>
                </select>
            </div>
            <div class="col-md-2 d-flex align-items-end gap-1">
                <button type="submit" class="btn btn-primary btn-sm w-100 fw-bold"><i class="fas fa-filter me-1"></i> Filter</button>
                <a href="client_services.php" class="btn btn-light btn-sm w-100 fw-bold">Reset</a>
            </div>
        </form>
      </div>
    </div>

    <!-- 4. STEP: DATA GRID -->
    <div class="card shadow">
      <div class="card-header bg-white py-3">
          <h5 class="mb-0 fw-bold text-dark"><i class="fas fa-table me-2"></i>Active Service Connections</h5>
      </div>
      <div class="card-body p-0">
        <div class="table-responsive">
          <table class="table table-hover align-middle mb-0">
            <thead>
              <tr>
                <th class="ps-3">S.No</th>
                <th>Client Name</th>
                <th>Service Name</th>
                <th>Category</th>
                <th class="text-center">Status</th>
                <th>Description</th>
                <th>Last Update</th>
                <th class="text-center">Action</th>
              </tr>
            </thead>
            <tbody>
              <?php if($services_list->num_rows > 0): $i=0; while($row = $services_list->fetch_assoc()): $i++; ?>
              <tr>
                <td class="ps-3 text-muted"><?= $i ?></td>
                <td class="fw-bold"><?= h($row['client_name']) ?></td>
                <td><span class="fw-semibold text-primary"><?= h($row['service_name']) ?></span></td>
                <td><span class="badge bg-secondary-subtle text-secondary border"><?= h($row['service_category']) ?></span></td>
                <td class="text-center">
                    <?php $s_val = $row['status'] ?: 'Active'; ?>
                    <span class="badge rounded-pill status-badge <?= ($s_val == 'Active') ? 'bg-success' : 'bg-danger' ?>">
                        <?= h($s_val) ?>
                    </span>
                </td>
                <td>
                    <span class="text-muted small description-text" title="<?= h($row['description']) ?>">
                        <?= h($row['description']) ?: '<span class="text-light-emphasis">No description provided</span>' ?>
                    </span>
                </td>
                <td class="small"><?= date('d-M-Y', strtotime($row['updated_date'] ?: $row['created_date'])) ?></td>
                <td class="text-center">
                    <div class="btn-group">
                        <button class="btn btn-sm btn-outline-primary" 
                                onclick="openEditModal(<?= $row['service_id'] ?>, '<?= h($row['status']) ?>', '<?= h($row['description']) ?>')" 
                                data-bs-toggle="modal" data-bs-target="#editModal">
                            <i class="fas fa-edit"></i>
                        </button>
                        <form method="post" class="d-inline" onsubmit="return confirm('Remove this service for <?= h($row['client_name']) ?>?')">
                            <input type="hidden" name="action" value="delete_service">
                            <input type="hidden" name="service_id" value="<?= $row['service_id'] ?>">
                            <button type="submit" class="btn btn-sm btn-outline-danger"><i class="fas fa-trash"></i></button>
                        </form>
                    </div>
                </td>
              </tr>
              <?php endwhile; else: ?>
                <tr><td colspan="8" class="text-center py-5 text-muted">No services match your filter criteria.</td></tr>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>
  </div>

  <!-- EDIT MODAL -->
  <div class="modal fade" id="editModal" tabindex="-1">
    <div class="modal-dialog">
      <form method="post" class="modal-content border-0 shadow-lg">
        <div class="modal-header bg-primary text-white">
          <h5 class="modal-title fw-bold"><i class="fas fa-info-circle me-2"></i>Update Service Details</h5>
          <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <input type="hidden" name="action" value="update_service_details">
          <input type="hidden" name="service_id" id="edit_service_id">
          
          <div class="mb-3">
            <label class="form-label fw-bold">Working Status</label>
            <select class="form-select" name="status" id="edit_status">
                <option value="Active">Active</option>
                <option value="Inactive">Inactive</option>
            </select>
            <div class="form-text small">Mark inactive if the service is stopped but needs to be kept in history.</div>
          </div>
          
          <div class="mb-3">
            <label class="form-label fw-bold">Service Scope / Notes</label>
            <textarea class="form-control" name="description" id="edit_description" rows="5" placeholder="Enter service scope, billing rules, or specific instructions..."></textarea>
          </div>
        </div>
        <div class="modal-footer bg-light">
          <button type="button" class="btn btn-secondary fw-bold" data-bs-dismiss="modal">Close</button>
          <button type="submit" class="btn btn-primary fw-bold">Save Changes</button>
        </div>
      </form>
    </div>
  </div>

  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
  <script>
    function openEditModal(id, status, desc) {
        document.getElementById('edit_service_id').value = id;
        document.getElementById('edit_status').value = status || 'Active';
        document.getElementById('edit_description').value = desc;
    }

    // Tooltip initialization (if needed)
    var tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'))
    var tooltipList = tooltipTriggerList.map(function (t) { return new bootstrap.Tooltip(t) });
  </script>
</body>
</html>