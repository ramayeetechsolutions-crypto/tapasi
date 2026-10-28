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
    $base = 'language_master.php';
    if (!empty($params)) $base .= '?' . http_build_query($params);
    header("Location: $base");
    exit;
}

// Handle POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'add' || $action === 'edit') {
        $language_name = trim($_POST['language_name'] ?? '');
        
        // Basic validation
        if ($language_name === '') {
            redirect_with(['msg' => 'Language Name is required.', 'type' => 'danger']);
        }
        
        if ($action === 'add') {
            // Check if language already exists
            $check_stmt = $mysqli->prepare("SELECT language_id FROM language_master WHERE language_name = ?");
            $check_stmt->bind_param("s", $language_name);
            $check_stmt->execute();
            $check_stmt->store_result();
            
            if ($check_stmt->num_rows > 0) {
                $check_stmt->close();
                redirect_with(['msg' => 'Language already exists.', 'type' => 'danger']);
            }
            $check_stmt->close();
            
            // Insert new language
            $stmt = $mysqli->prepare("INSERT INTO language_master (language_name, status, created_date) VALUES (?, 'Active', NOW())");
            $stmt->bind_param("s", $language_name);
            
            if ($stmt->execute()) {
                redirect_with(['msg' => "Language '$language_name' added successfully", 'type' => 'success']);
            } else {
                redirect_with(['msg' => 'Error adding language: ' . $stmt->error, 'type' => 'danger']);
            }
            $stmt->close();
        }
        
        if ($action === 'edit') {
            $language_id = (int)($_POST['language_id'] ?? 0);
            
            if ($language_id > 0) {
                // Check if language already exists (excluding current one)
                $check_stmt = $mysqli->prepare("SELECT language_id FROM language_master WHERE language_name = ? AND language_id != ?");
                $check_stmt->bind_param("si", $language_name, $language_id);
                $check_stmt->execute();
                $check_stmt->store_result();
                
                if ($check_stmt->num_rows > 0) {
                    $check_stmt->close();
                    redirect_with(['msg' => 'Language already exists.', 'type' => 'danger']);
                }
                $check_stmt->close();
                
                // Update language
                $stmt = $mysqli->prepare("UPDATE language_master SET language_name = ? WHERE language_id = ?");
                $stmt->bind_param("si", $language_name, $language_id);
                
                if ($stmt->execute()) {
                    redirect_with(['msg' => "Language updated successfully", 'type' => 'success']);
                } else {
                    redirect_with(['msg' => 'Error updating language: ' . $stmt->error, 'type' => 'danger']);
                }
                $stmt->close();
            }
        }
    }
    
    if ($action === 'delete') {
        $language_id = (int)($_POST['language_id'] ?? 0);
        $language_name = '';
        
        if ($language_id > 0) {
            // Get language name for message
            $res = $mysqli->prepare("SELECT language_name FROM language_master WHERE language_id = ?");
            $res->bind_param("i", $language_id);
            $res->execute();
            $res->bind_result($language_name);
            $res->fetch();
            $res->close();
            
            // Delete language
            $del = $mysqli->prepare("DELETE FROM language_master WHERE language_id = ?");
            $del->bind_param("i", $language_id);
            $del->execute();
            $del->close();
            
            redirect_with(['msg' => "Language '$language_name' deleted", 'type' => 'warning']);
        }
    }
    
    if ($action === 'toggle') {
        $language_id = (int)($_POST['language_id'] ?? 0);
        
        if ($language_id > 0) {
            $res = $mysqli->prepare("SELECT status, language_name FROM language_master WHERE language_id = ?");
            $res->bind_param("i", $language_id);
            $res->execute();
            $res->bind_result($current_status, $language_name);
            $res->fetch();
            $res->close();
            
            if ($language_name) {
                $newStatus = ($current_status === 'Active') ? 'Inactive' : 'Active';
                
                $upd = $mysqli->prepare("UPDATE language_master SET status = ? WHERE language_id = ?");
                $upd->bind_param("si", $newStatus, $language_id);
                $upd->execute();
                $upd->close();
                
                redirect_with(['msg' => "Language '$language_name' status changed to $newStatus", 'type' => 'info']);
            }
        }
    }
    
    redirect_with();
}

// Get mode
$mode = $_GET['mode'] ?? 'view';
$editLanguage = null;

if ($mode === 'edit') {
    $id = (int)($_GET['id'] ?? 0);
    if ($id > 0) {
        $stmt = $mysqli->prepare("SELECT language_id, language_name, status, created_date FROM language_master WHERE language_id = ?");
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $editLanguage = $stmt->get_result()->fetch_assoc();
        $stmt->close();
    }
    if (!$editLanguage) $mode = 'view';
}

// Get search and filter parameters
$q = trim($_GET['q'] ?? '');
$fstatus = $_GET['status'] ?? '';
$sort = $_GET['sort'] ?? 'created_date';
$dir = strtolower($_GET['dir'] ?? 'desc');

// Build query
$sql = "SELECT language_id, language_name, status, created_date FROM language_master";
$cond = [];
$params = [];
$types = '';

if ($q !== '') {
    $cond[] = "language_name LIKE ?";
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
    $languages = $stmt->get_result();
} else {
    $languages = $mysqli->query($sql);
}

// If query fails, show empty result
if (!$languages) {
    $languages = false;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Language Master</title>
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
            <h4><i class="fas fa-language me-2"></i><?= $mode === 'edit' ? 'Edit Language' : 'Add New Language' ?></h4>
            <a href="language_master.php" class="btn btn-secondary btn-sm">
                <i class="fas fa-arrow-left me-1"></i> Back to List
            </a>
        </div>

        <div class="card">
            <div class="card-body">
                <form method="post" autocomplete="off">
                    <input type="hidden" name="action" value="<?= $mode ?>">
                    <?php if($mode === 'edit'): ?>
                    <input type="hidden" name="language_id" value="<?= (int)($editLanguage['language_id'] ?? 0) ?>">
                    <?php endif; ?>

                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label for="language_name" class="form-label">Language Name <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" id="language_name" name="language_name" 
                                       value="<?= h($editLanguage['language_name'] ?? '') ?>" 
                                       placeholder="Enter language name" required>
                                <div class="form-text">Enter the name of the language (e.g., English, Spanish, French)</div>
                            </div>
                        </div>
                    </div>

                    <div class="d-flex gap-2 mt-4">
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-save me-1"></i>
                            <?= $mode === 'edit' ? 'Update Language' : 'Save Language' ?>
                        </button>
                        <a href="language_master.php" class="btn btn-outline-secondary">Cancel</a>
                    </div>
                </form>
            </div>
        </div>

        <?php else: ?>

        <!-- View Mode -->
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h4><i class="fas fa-language me-2"></i>Language Master</h4>
            <a href="language_master.php?mode=add" class="btn btn-success btn-sm">
                <i class="fas fa-plus me-1"></i> Add Language
            </a>
        </div>

        <!-- Search and Filter Form -->
        <div class="card mb-4">
            <div class="card-body">
                <form method="get" class="row g-3 align-items-center">
                    <div class="col-md-4">
                        <input type="text" name="q" class="form-control form-control-sm" 
                               placeholder="Search languages..." value="<?= h($q) ?>">
                    </div>
                    <div class="col-md-4">
                        <select name="status" class="form-select form-select-sm">
                            <option value="">All Status</option>
                            <option value="Active" <?= $fstatus === 'Active' ? 'selected' : '' ?>>Active</option>
                            <option value="Inactive" <?= $fstatus === 'Inactive' ? 'selected' : '' ?>>Inactive</option>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <select name="sort" class="form-select form-select-sm">
                            <option value="language_name" <?= $sort === 'language_name' ? 'selected' : '' ?>>Sort by Name</option>
                            <option value="created_date" <?= $sort === 'created_date' ? 'selected' : '' ?>>Sort by Date</option>
                        </select>
                    </div>
                
                    <!-- <div class="col-md-2">
                        <select name="dir" class="form-select form-select-sm">
                            <option value="asc" <?= $dir === 'asc' ? 'selected' : '' ?>>Ascending</option>
                            <option value="desc" <?= $dir === 'desc' ? 'selected' : '' ?>>Descending</option>
                        </select>
                    </div>  -->
                    
                    <div class="col-md-12 mt-2">
                        <button type="submit" class="btn btn-primary btn-sm me-2">
                            <i class="fas fa-search me-1"></i> Search
                        </button>
                        <a href="language_master.php" class="btn btn-secondary btn-sm">
                            <i class="fas fa-times me-1"></i> Clear
                        </a>
                    </div>
                </form>
            </div>
        </div>

        <!-- Languages Table -->
        <div class="card">
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th width="50">S.NO</th>
                                <th>Language Name</th>
                                <th width="100">Status</th>
                                <th width="120">Created Date</th>
                                <th width="150" class="text-center">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if($languages && $languages->num_rows > 0): ?>
                                <?php while($row = $languages->fetch_assoc()): ?>
                                <tr>
                                    <td class="fw-bold"><?= (int)$row['language_id'] ?></td>
                                    <td>
                                        <span class="fw-semibold"><?= h($row['language_name']) ?></span>
                                    </td>
                                    <td>
                                        <form method="post" style="display:inline;">
                                            <input type="hidden" name="action" value="toggle">
                                            <input type="hidden" name="language_id" value="<?= (int)$row['language_id'] ?>">
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
                                            <a href="language_master.php?mode=edit&id=<?= (int)$row['language_id'] ?>" 
                                               class="btn btn-warning" title="Edit Language">
                                                <i class="fas fa-edit"></i>
                                            </a>
                                            <form method="post" style="display:inline;" 
                                                  onsubmit="return confirm('Are you sure you want to delete <?= h($row['language_name']) ?>?')">
                                                <input type="hidden" name="action" value="delete">
                                                <input type="hidden" name="language_id" value="<?= (int)$row['language_id'] ?>">
                                                <button type="submit" class="btn btn-danger" title="Delete Language">
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
                                            <i class="fas fa-language fa-3x mb-3"></i>
                                            <h5>No languages found</h5>
                                            <p class="mb-0">Get started by adding your first language.</p>
                                            <a href="language_master.php?mode=add" class="btn btn-primary mt-2">
                                                <i class="fas fa-plus me-1"></i> Add Language
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
            const filterSelects = document.querySelectorAll('select[name="status"], select[name="sort"]');
            // select[name="dir"] 
            
           
            filterSelects.forEach(select => {
                select.addEventListener('change', function() {
                    this.form.submit();
                });
            });
        });
    </script>
</body>
</html>