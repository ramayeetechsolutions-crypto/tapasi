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
    $base = 'template_master.php';
    if (!empty($params)) $base .= '?' . http_build_query($params);
    header("Location: $base");
    exit;
}

// Handle POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'add' || $action === 'edit') {
        $template_name = trim($_POST['template_name'] ?? '');
        
        // Basic validation
        if ($template_name === '') {
            redirect_with(['msg' => 'Template Name is required.', 'type' => 'danger']);
        }
        
        if ($action === 'add') {
            // Check if template already exists
            $check_stmt = $mysqli->prepare("SELECT template_id FROM template_master WHERE template_name = ?");
            $check_stmt->bind_param("s", $template_name);
            $check_stmt->execute();
            $check_stmt->store_result();
            
            if ($check_stmt->num_rows > 0) {
                $check_stmt->close();
                redirect_with(['msg' => 'Template already exists.', 'type' => 'danger']);
            }
            $check_stmt->close();
            
            // Insert new template
            $stmt = $mysqli->prepare("INSERT INTO template_master (template_name, status, created_date) VALUES (?, 'Active', NOW())");
            $stmt->bind_param("s", $template_name);
            
            if ($stmt->execute()) {
                redirect_with(['msg' => "Template '$template_name' added successfully", 'type' => 'success']);
            } else {
                redirect_with(['msg' => 'Error adding template: ' . $stmt->error, 'type' => 'danger']);
            }
            $stmt->close();
        }
        
        if ($action === 'edit') {
            $template_id = (int)($_POST['template_id'] ?? 0);
            
            if ($template_id > 0) {
                // Check if template already exists (excluding current one)
                $check_stmt = $mysqli->prepare("SELECT template_id FROM template_master WHERE template_name = ? AND template_id != ?");
                $check_stmt->bind_param("si", $template_name, $template_id);
                $check_stmt->execute();
                $check_stmt->store_result();
                
                if ($check_stmt->num_rows > 0) {
                    $check_stmt->close();
                    redirect_with(['msg' => 'Template already exists.', 'type' => 'danger']);
                }
                $check_stmt->close();
                
                // Update template
                $stmt = $mysqli->prepare("UPDATE template_master SET template_name = ? WHERE template_id = ?");
                $stmt->bind_param("si", $template_name, $template_id);
                
                if ($stmt->execute()) {
                    redirect_with(['msg' => "Template updated successfully", 'type' => 'success']);
                } else {
                    redirect_with(['msg' => 'Error updating template: ' . $stmt->error, 'type' => 'danger']);
                }
                $stmt->close();
            }
        }
    }
    
    if ($action === 'delete') {
        $template_id = (int)($_POST['template_id'] ?? 0);
        $template_name = '';
        
        if ($template_id > 0) {
            // Get template name for message
            $res = $mysqli->prepare("SELECT template_name FROM template_master WHERE template_id = ?");
            $res->bind_param("i", $template_id);
            $res->execute();
            $res->bind_result($template_name);
            $res->fetch();
            $res->close();
            
            // Delete template
            $del = $mysqli->prepare("DELETE FROM template_master WHERE template_id = ?");
            $del->bind_param("i", $template_id);
            $del->execute();
            $del->close();
            
            redirect_with(['msg' => "Template '$template_name' deleted", 'type' => 'warning']);
        }
    }
    
    if ($action === 'toggle') {
        $template_id = (int)($_POST['template_id'] ?? 0);
        
        if ($template_id > 0) {
            $res = $mysqli->prepare("SELECT status, template_name FROM template_master WHERE template_id = ?");
            $res->bind_param("i", $template_id);
            $res->execute();
            $res->bind_result($current_status, $template_name);
            $res->fetch();
            $res->close();
            
            if ($template_name) {
                $newStatus = ($current_status === 'Active') ? 'Inactive' : 'Active';
                
                $upd = $mysqli->prepare("UPDATE template_master SET status = ? WHERE template_id = ?");
                $upd->bind_param("si", $newStatus, $template_id);
                $upd->execute();
                $upd->close();
                
                redirect_with(['msg' => "Template '$template_name' status changed to $newStatus", 'type' => 'info']);
            }
        }
    }
    
    redirect_with();
}

// Get mode
$mode = $_GET['mode'] ?? 'view';
$editTemplate = null;

if ($mode === 'edit') {
    $id = (int)($_GET['id'] ?? 0);
    if ($id > 0) {
        $stmt = $mysqli->prepare("SELECT template_id, template_name, status, created_date FROM template_master WHERE template_id = ?");
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $editTemplate = $stmt->get_result()->fetch_assoc();
        $stmt->close();
    }
    if (!$editTemplate) $mode = 'view';
}

// Get search and filter parameters
$q = trim($_GET['q'] ?? '');
$fstatus = $_GET['status'] ?? '';
$sort = $_GET['sort'] ?? 'created_date';
$dir = strtolower($_GET['dir'] ?? 'desc');

// Build query
$sql = "SELECT template_id, template_name, status, created_date FROM template_master";
$cond = [];
$params = [];
$types = '';

if ($q !== '') {
    $cond[] = "template_name LIKE ?";
    $like = "%$q%";
    $params[] = $like;
    $types .= 's';
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
    $templates = $stmt->get_result();
} else {
    $templates = $mysqli->query($sql);
}

// If query fails, show empty result
if (!$templates) {
    $templates = false;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Template Master</title>
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
        .template-item {
            padding: 8px 12px;
            margin: 4px 0;
            background: #f8f9fa;
            border-radius: 4px;
            border-left: 4px solid #007bff;
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
            <h4><i class="fas fa-file-alt me-2"></i><?= $mode === 'edit' ? 'Edit Template' : 'Add New Template' ?></h4>
            <a href="template_master.php" class="btn btn-secondary btn-sm">
                <i class="fas fa-arrow-left me-1"></i> Back to List
            </a>
        </div>

        <div class="card">
            <div class="card-body">
                <form method="post" autocomplete="off">
                    <input type="hidden" name="action" value="<?= $mode ?>">
                    <?php if($mode === 'edit'): ?>
                    <input type="hidden" name="template_id" value="<?= (int)($editTemplate['template_id'] ?? 0) ?>">
                    <?php endif; ?>

                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label for="template_name" class="form-label">Template Name <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" id="template_name" name="template_name" 
                                       value="<?= h($editTemplate['template_name'] ?? '') ?>" 
                                       placeholder="Enter template name" required>
                                <div class="form-text">Enter a descriptive name for the template (e.g., Invoice Template, Welcome Email, etc.)</div>
                            </div>
                        </div>
                    </div>

                    <div class="d-flex gap-2 mt-4">
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-save me-1"></i>
                            <?= $mode === 'edit' ? 'Update Template' : 'Save Template' ?>
                        </button>
                        <a href="template_master.php" class="btn btn-outline-secondary">Cancel</a>
                    </div>
                </form>
            </div>
        </div>

        <?php else: ?>

        <!-- View Mode -->
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h4><i class="fas fa-file-alt me-2"></i>Template Master</h4>
            <a href="template_master.php?mode=add" class="btn btn-success btn-sm">
                <i class="fas fa-plus me-1"></i> Add Template
            </a>
        </div>

        <!-- Search and Filter Form -->
        <div class="card mb-4">
            <div class="card-body">
                <form method="get" class="row g-3 align-items-center">
                    <div class="col-md-5">
                        <input type="text" name="q" class="form-control form-control-sm" 
                               placeholder="Search templates..." value="<?= h($q) ?>">
                    </div>
                    <div class="col-md-3">
                        <select name="status" class="form-select form-select-sm">
                            <option value="">All Status</option>
                            <option value="Active" <?= $fstatus === 'Active' ? 'selected' : '' ?>>Active</option>
                            <option value="Inactive" <?= $fstatus === 'Inactive' ? 'selected' : '' ?>>Inactive</option>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <select name="sort" class="form-select form-select-sm">
                            <option value="template_name" <?= $sort === 'template_name' ? 'selected' : '' ?>>Sort by Name</option>
                            <option value="created_date" <?= $sort === 'created_date' ? 'selected' : '' ?>>Sort by Date</option>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <select name="dir" class="form-select form-select-sm">
                            <option value="asc" <?= $dir === 'asc' ? 'selected' : '' ?>>Ascending</option>
                            <option value="desc" <?= $dir === 'desc' ? 'selected' : '' ?>>Descending</option>
                        </select>
                    </div>
                    <div class="col-md-12 mt-2">
                        <button type="submit" class="btn btn-primary btn-sm me-2">
                            <i class="fas fa-search me-1"></i> Search
                        </button>
                        <a href="template_master.php" class="btn btn-secondary btn-sm">
                            <i class="fas fa-times me-1"></i> Clear
                        </a>
                    </div>
                </form>
            </div>
        </div>

        <!-- Templates Table -->
        <div class="card">
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th width="50">ID</th>
                                <th>Template Name</th>
                                <th width="100">Status</th>
                                <th width="120">Created Date</th>
                                <th width="150" class="text-center">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if($templates && $templates->num_rows > 0): ?>
                                <?php while($row = $templates->fetch_assoc()): ?>
                                <tr>
                                    <td class="fw-bold"><?= (int)$row['template_id'] ?></td>
                                    <td>
                                        <div class="d-flex align-items-center">
                                            <i class="fas fa-file-alt text-primary me-2"></i>
                                            <span class="fw-semibold"><?= h($row['template_name']) ?></span>
                                        </div>
                                    </td>
                                    <td>
                                        <form method="post" style="display:inline;">
                                            <input type="hidden" name="action" value="toggle">
                                            <input type="hidden" name="template_id" value="<?= (int)$row['template_id'] ?>">
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
                                            <a href="template_master.php?mode=edit&id=<?= (int)$row['template_id'] ?>" 
                                               class="btn btn-warning" title="Edit Template">
                                                <i class="fas fa-edit"></i>
                                            </a>
                                            <form method="post" style="display:inline;" 
                                                  onsubmit="return confirm('Are you sure you want to delete <?= h($row['template_name']) ?>?')">
                                                <input type="hidden" name="action" value="delete">
                                                <input type="hidden" name="template_id" value="<?= (int)$row['template_id'] ?>">
                                                <button type="submit" class="btn btn-danger" title="Delete Template">
                                                    <i class="fas fa-trash"></i>
                                                </button>
                                            </form>
                                        </div>
                                    </td>
                                </tr>
                                <?php endwhile; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="5" class="text-center py-5">
                                        <div class="text-muted">
                                            <i class="fas fa-file-alt fa-3x mb-3"></i>
                                            <h5>No templates found</h5>
                                            <p class="mb-0">Get started by adding your first template.</p>
                                            <a href="template_master.php?mode=add" class="btn btn-primary mt-2">
                                                <i class="fas fa-plus me-1"></i> Add Template
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