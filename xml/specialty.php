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
    $base = 'specialty.php';
    if (!empty($params)) $base .= '?' . http_build_query($params);
    header("Location: $base");
    exit;
}

// Handle POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'add' || $action === 'edit') {
        $specialty_name = trim($_POST['specialty_name'] ?? '');
        $description = trim($_POST['description'] ?? '');
        
        // Basic validation
        if ($specialty_name === '') {
            redirect_with(['msg' => 'Journal Specialty Name is required.', 'type' => 'danger']);
        }
        
        if ($action === 'add') {
            // Check if specialty already exists
            $check_stmt = $mysqli->prepare("SELECT specialty_id FROM journal_specialty_master WHERE specialty_name = ?");
            $check_stmt->bind_param("s", $specialty_name);
            $check_stmt->execute();
            $check_stmt->store_result();
            
            if ($check_stmt->num_rows > 0) {
                $check_stmt->close();
                redirect_with(['msg' => 'Journal Specialty already exists.', 'type' => 'danger']);
            }
            $check_stmt->close();
            
            // Insert new specialty
            $stmt = $mysqli->prepare("INSERT INTO journal_specialty_master (specialty_name, description, status, created_date) VALUES (?, ?, 'Active', NOW())");
            $stmt->bind_param("ss", $specialty_name, $description);
            
            if ($stmt->execute()) {
                redirect_with(['msg' => "Journal Specialty '$specialty_name' added successfully", 'type' => 'success']);
            } else {
                redirect_with(['msg' => 'Error adding journal specialty: ' . $stmt->error, 'type' => 'danger']);
            }
            $stmt->close();
        }
        
        if ($action === 'edit') {
            $specialty_id = (int)($_POST['specialty_id'] ?? 0);
            
            if ($specialty_id > 0) {
                // Check if specialty already exists (excluding current one)
                $check_stmt = $mysqli->prepare("SELECT specialty_id FROM journal_specialty_master WHERE specialty_name = ? AND specialty_id != ?");
                $check_stmt->bind_param("si", $specialty_name, $specialty_id);
                $check_stmt->execute();
                $check_stmt->store_result();
                
                if ($check_stmt->num_rows > 0) {
                    $check_stmt->close();
                    redirect_with(['msg' => 'Journal Specialty already exists.', 'type' => 'danger']);
                }
                $check_stmt->close();
                
                // Update specialty
                $stmt = $mysqli->prepare("UPDATE journal_specialty_master SET specialty_name = ?, description = ? WHERE specialty_id = ?");
                $stmt->bind_param("ssi", $specialty_name, $description, $specialty_id);
                
                if ($stmt->execute()) {
                    redirect_with(['msg' => "Journal Specialty updated successfully", 'type' => 'success']);
                } else {
                    redirect_with(['msg' => 'Error updating journal specialty: ' . $stmt->error, 'type' => 'danger']);
                }
                $stmt->close();
            }
        }
    }
    
    if ($action === 'delete') {
        $specialty_id = (int)($_POST['specialty_id'] ?? 0);
        $specialty_name = '';
        
        if ($specialty_id > 0) {
            // Get specialty name for message
            $res = $mysqli->prepare("SELECT specialty_name FROM journal_specialty_master WHERE specialty_id = ?");
            $res->bind_param("i", $specialty_id);
            $res->execute();
            $res->bind_result($specialty_name);
            $res->fetch();
            $res->close();
            
            // Delete specialty
            $del = $mysqli->prepare("DELETE FROM journal_specialty_master WHERE specialty_id = ?");
            $del->bind_param("i", $specialty_id);
            $del->execute();
            $del->close();
            
            redirect_with(['msg' => "Journal Specialty '$specialty_name' deleted", 'type' => 'warning']);
        }
    }
    
    if ($action === 'toggle') {
        $specialty_id = (int)($_POST['specialty_id'] ?? 0);
        
        if ($specialty_id > 0) {
            $res = $mysqli->prepare("SELECT status, specialty_name FROM journal_specialty_master WHERE specialty_id = ?");
            $res->bind_param("i", $specialty_id);
            $res->execute();
            $res->bind_result($current_status, $specialty_name);
            $res->fetch();
            $res->close();
            
            if ($specialty_name) {
                $newStatus = ($current_status === 'Active') ? 'Inactive' : 'Active';
                
                $upd = $mysqli->prepare("UPDATE journal_specialty_master SET status = ? WHERE specialty_id = ?");
                $upd->bind_param("si", $newStatus, $specialty_id);
                $upd->execute();
                $upd->close();
                
                redirect_with(['msg' => "Journal Specialty '$specialty_name' status changed to $newStatus", 'type' => 'info']);
            }
        }
    }
    
    redirect_with();
}

// Get mode
$mode = $_GET['mode'] ?? 'view';
$editSpecialty = null;

if ($mode === 'edit') {
    $id = (int)($_GET['id'] ?? 0);
    if ($id > 0) {
        $stmt = $mysqli->prepare("SELECT specialty_id, specialty_name, description, status, created_date FROM journal_specialty_master WHERE specialty_id = ?");
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $editSpecialty = $stmt->get_result()->fetch_assoc();
        $stmt->close();
    }
    if (!$editSpecialty) $mode = 'view';
}

// Get search and filter parameters
$q = trim($_GET['q'] ?? '');
$fstatus = $_GET['status'] ?? '';
$sort = $_GET['sort'] ?? 'created_date';
$dir = strtolower($_GET['dir'] ?? 'desc');

// Build query
$sql = "SELECT specialty_id, specialty_name, description, status, created_date FROM journal_specialty_master";
$cond = [];
$params = [];
$types = '';

if ($q !== '') {
    $cond[] = "(specialty_name LIKE ? OR description LIKE ?)";
    $like = "%$q%";
    $params[] = $like;
    $params[] = $like;
    $types .= 'ss';
}

if ($fstatus === 'Active' || $fstatus === 'Inactive') {
    $cond[] = "status = ?";
    $params[] = $fstatus;
    $types .= 's';
}

if (!empty($cond)) {
    $sql .= " WHERE " . implode(" AND ", $cond);
}

$sql .= " ORDER BY $sort $dir";

// Execute query
if (!empty($types)) {
    $stmt = $mysqli->prepare($sql);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $specialties = $stmt->get_result();
} else {
    $specialties = $mysqli->query($sql);
}

// If query fails, show empty result
if (!$specialties) {
    $specialties = false;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Journal Specialty Master</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body {
            background-color: #f8f9fa;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }
        .main-content {
            padding: 20px;
            max-width: 1200px;
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
        .specialty-badge {
            background: linear-gradient(45deg, #6c757d, #495057);
            color: white;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 0.85em;
            display: inline-block;
            margin: 2px;
        }
        .description-text {
            font-size: 0.9em;
            color: #6c757d;
            max-width: 300px;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }
        .medical-icon {
            color: #0d6efd;
            font-size: 1.2em;
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

        <?php if($mode === 'add' || $mode === 'edit'): ?>
        
        <!-- Add/Edit Form -->
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h4><i class="fas fa-book-medical me-2 medical-icon"></i><?= $mode === 'edit' ? 'Edit Journal Specialty' : 'Add New Journal Specialty' ?></h4>
            <a href="specialty.php" class="btn btn-secondary btn-sm">
                <i class="fas fa-arrow-left me-1"></i> Back to List
            </a>
        </div>

        <div class="card">
            <div class="card-body">
                <form method="post" autocomplete="off">
                    <input type="hidden" name="action" value="<?= $mode ?>">
                    <?php if($mode === 'edit'): ?>
                    <input type="hidden" name="specialty_id" value="<?= (int)($editSpecialty['specialty_id'] ?? 0) ?>">
                    <?php endif; ?>

                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label for="specialty_name" class="form-label">Journal Specialty Name <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" id="specialty_name" name="specialty_name" 
                                       value="<?= h($editSpecialty['specialty_name'] ?? '') ?>" 
                                       placeholder="Enter journal specialty name" required>
                                <div class="form-text">Enter the name of the journal specialty (e.g., Ophthalmology, Surgery, Science, Research)</div>
                            </div>
                        </div>
                        
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label for="description" class="form-label">Description</label>
                                <textarea class="form-control" id="description" name="description" 
                                          rows="2" placeholder="Enter description (optional)"><?= h($editSpecialty['description'] ?? '') ?></textarea>
                                <div class="form-text">Brief description of the journal specialty</div>
                            </div>
                        </div>
                    </div>

                    <div class="d-flex gap-2 mt-4">
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-save me-1"></i>
                            <?= $mode === 'edit' ? 'Update Specialty' : 'Save Specialty' ?>
                        </button>
                        <a href="specialty.php" class="btn btn-outline-secondary">Cancel</a>
                    </div>
                </form>
            </div>
        </div>

        <?php else: ?>

        <!-- View Mode -->
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h4><i class="fas fa-book-medical me-2 medical-icon"></i>Journal Specialty Master</h4>
            <a href="specialty.php?mode=add" class="btn btn-success btn-sm">
                <i class="fas fa-plus me-1"></i> Add Specialty
            </a>
        </div>

        <!-- Search and Filter Form -->
        <div class="card mb-4">
            <div class="card-body">
                <form method="get" class="row g-3 align-items-center">
                    <div class="col-md-5">
                        <input type="text" name="q" class="form-control form-control-sm" 
                               placeholder="Search specialties..." value="<?= h($q) ?>">
                    </div>
                    <div class="col-md-3">
                        <select name="status" class="form-select form-select-sm">
                            <option value="">All Status</option>
                            <option value="Active" <?= $fstatus === 'Active' ? 'selected' : '' ?>>Active</option>
                            <option value="Inactive" <?= $fstatus === 'Inactive' ? 'selected' : '' ?>>Inactive</option>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <select name="sort" class="form-select form-select-sm">
                            <option value="specialty_name" <?= $sort === 'specialty_name' ? 'selected' : '' ?>>Sort by Name</option>
                            <option value="created_date" <?= $sort === 'created_date' ? 'selected' : '' ?>>Sort by Date</option>
                        </select>
                    </div>
                    <!-- <div class="col-md-2">
                        <select name="dir" class="form-select form-select-sm">
                            <option value="asc" <?= $dir === 'asc' ? 'selected' : '' ?>>Ascending</option>
                            <option value="desc" <?= $dir === 'desc' ? 'selected' : '' ?>>Descending</option>
                        </select>
                    </div> -->
                    <div class="col-md-12 mt-2">
                        <button type="submit" class="btn btn-primary btn-sm me-2">
                            <i class="fas fa-search me-1"></i> Search
                        </button>
                        <a href="specialty.php" class="btn btn-secondary btn-sm">
                            <i class="fas fa-times me-1"></i> Clear
                        </a>
                    </div>
                </form>
            </div>
        </div>

        <!-- Journal Specialties Table -->
        <div class="card">
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th width="50">ID</th>
                                <th>Journal Specialty Name</th>
                                <th>Description</th>
                                <th width="100">Status</th>
                                <th width="120">Created Date</th>
                                <th width="150" class="text-center">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if($specialties && $specialties->num_rows > 0): ?>
                                <?php while($row = $specialties->fetch_assoc()): ?>
                                <tr>
                                    <td class="fw-bold"><?= (int)$row['specialty_id'] ?></td>
                                    <td>
                                        <div class="d-flex align-items-center">
                                            <i class="fas fa-stethoscope me-2 medical-icon"></i>
                                            <div>
                                                <span class="fw-semibold"><?= h($row['specialty_name']) ?></span>
                                                <?php if($row['specialty_name'] === 'Ophthalmology'): ?>
                                                <span class="badge bg-info ms-2">Medical</span>
                                                <?php elseif($row['specialty_name'] === 'Surgery'): ?>
                                                <span class="badge bg-danger ms-2">Surgical</span>
                                                <?php elseif($row['specialty_name'] === 'Science' || $row['specialty_name'] === 'Research'): ?>
                                                <span class="badge bg-success ms-2">Research</span>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </td>
                                    <td>
                                        <div class="description-text">
                                            <?= !empty($row['description']) ? h($row['description']) : '<span class="text-muted fst-italic">No description</span>' ?>
                                        </div>
                                    </td>
                                    <td>
                                        <form method="post" style="display:inline;">
                                            <input type="hidden" name="action" value="toggle">
                                            <input type="hidden" name="specialty_id" value="<?= (int)$row['specialty_id'] ?>">
                                            <button type="submit" class="btn btn-sm <?= $row['status'] === 'Active' ? 'btn-success' : 'btn-danger' ?>">
                                                <?= h($row['status']) ?>
                                            </button>
                                        </form>
                                    </td>
                                    <td>
                                        <small class="text-muted"><?= h(formatDate($row['created_date'])) ?></small>
                                    </td>
                                    <td class="text-center">
                                        <div class="btn-group btn-group-sm" role="group">
                                            <a href="specialty.php?mode=edit&id=<?= (int)$row['specialty_id'] ?>" 
                                               class="btn btn-warning" title="Edit Specialty">
                                                <i class="fas fa-edit"></i>
                                            </a>
                                            <form method="post" style="display:inline;" 
                                                  onsubmit="return confirm('Are you sure you want to delete <?= h($row['specialty_name']) ?>?')">
                                                <input type="hidden" name="action" value="delete">
                                                <input type="hidden" name="specialty_id" value="<?= (int)$row['specialty_id'] ?>">
                                                <button type="submit" class="btn btn-danger" title="Delete Specialty">
                                                    <i class="fas fa-trash"></i>
                                                </button>
                                            </form>
                                        </div>
                                    </td>
                                </tr>
                                <?php endwhile; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="6" class="text-center py-5">
                                        <div class="text-muted">
                                            <i class="fas fa-book-medical fa-3x mb-3 medical-icon"></i>
                                            <h5>No journal specialties found</h5>
                                            <p class="mb-0">Get started by adding your first journal specialty.</p>
                                            <a href="specialty.php?mode=add" class="btn btn-primary mt-2">
                                                <i class="fas fa-plus me-1"></i> Add Specialty
                                            </a>
                                        </div>
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- Quick Stats (Optional) -->
        <?php if($specialties && $specialties->num_rows > 0): ?>
        <?php 
            // Reset pointer to count again
            $specialties->data_seek(0);
            $total = $specialties->num_rows;
            $activeCount = 0;
            $inactiveCount = 0;
            while($row = $specialties->fetch_assoc()) {
                if ($row['status'] === 'Active') $activeCount++;
                else $inactiveCount++;
            }
            $specialties->data_seek(0); // Reset for display if needed
        ?>
        <div class="row mt-4">
            <div class="col-md-4">
                <div class="card text-white bg-primary">
                    <div class="card-body">
                        <h5 class="card-title"><i class="fas fa-list me-2"></i>Total Specialties</h5>
                        <h2 class="card-text"><?= $total ?></h2>
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="card text-white bg-success">
                    <div class="card-body">
                        <h5 class="card-title"><i class="fas fa-check-circle me-2"></i>Active</h5>
                        <h2 class="card-text"><?= $activeCount ?></h2>
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="card text-white bg-secondary">
                    <div class="card-body">
                        <h5 class="card-title"><i class="fas fa-times-circle me-2"></i>Inactive</h5>
                        <h2 class="card-text"><?= $inactiveCount ?></h2>
                    </div>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <?php endif; ?>
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

            // Auto-submit form on filter changes
            const filterSelects = document.querySelectorAll('select[name="status"], select[name="sort"], select[name="dir"]');
            filterSelects.forEach(select => {
                select.addEventListener('change', function() {
                    this.form.submit();
                });
            });
        });
    </script>
</body>
</html>