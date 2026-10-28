<?php
require 'db.php';
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

if (session_status() === PHP_SESSION_NONE) { 
    session_start(); 
}

// Helper functions
function h($s) { 
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); 
}

function redirect_with($params = []) {
    $base = 'contacts.php';
    if (!empty($params)) $base .= '?' . http_build_query($params);
    header("Location: $base");
    exit;
}

// Handle POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'add' || $action === 'edit') {
        $contact_name = trim($_POST['contact_name'] ?? '');
        $contact_no = trim($_POST['contact_no'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $alternate_no = trim($_POST['alternate_no'] ?? '');
        // NEW FIELDS
        $email = trim($_POST['email'] ?? '');
        $bank = trim($_POST['bank'] ?? '');
        $bank_branch = trim($_POST['bank_branch'] ?? '');
        $account_no = trim($_POST['account_no'] ?? '');
        $ifsc_code = trim($_POST['ifsc_code'] ?? '');
        
        // Basic validation
        if ($contact_name === '') {
            redirect_with(['msg' => 'Contact Name is required.', 'type' => 'danger']);
        }
        
        if ($action === 'add') {
            // Insert new contact (Added email and bank_branch)
            $stmt = $mysqli->prepare("INSERT INTO tbl_contacts (contact_name, contact_no, description, alternate_no, email, bank, bank_branch, account_no, ifsc_code, created_at, created_ip) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), ?)");
            $stmt->bind_param("ssssssssss", $contact_name, $contact_no, $description, $alternate_no, $email, $bank, $bank_branch, $account_no, $ifsc_code, $_SERVER['REMOTE_ADDR']);
            
            if ($stmt->execute()) {
                redirect_with(['msg' => "Contact '$contact_name' added successfully", 'type' => 'success']);
            } else {
                redirect_with(['msg' => 'Error adding contact: ' . $stmt->error, 'type' => 'danger']);
            }
            $stmt->close();
        } 
        elseif ($action === 'edit') {
            $id = (int)($_POST['id'] ?? 0);
            
            if ($id > 0) {
                // Update contact (Added email and bank_branch)
                $sql = "UPDATE tbl_contacts SET 
                        contact_name = ?, contact_no = ?, description = ?, 
                        alternate_no = ?, email = ?, 
                        bank = ?, bank_branch = ?, account_no = ?, ifsc_code = ?, 
                        updated_at = NOW(), updated_ip = ? 
                        WHERE id = ?";
                
                $stmt = $mysqli->prepare($sql);
                $stmt->bind_param("ssssssssssi", $contact_name, $contact_no, $description, $alternate_no, $email, $bank, $bank_branch, $account_no, $ifsc_code, $_SERVER['REMOTE_ADDR'], $id);
                
                if ($stmt->execute()) {
                    redirect_with(['msg' => "Contact updated successfully", 'type' => 'success']);
                } else {
                    redirect_with(['msg' => 'Error updating contact: ' . $stmt->error, 'type' => 'danger']);
                }
                $stmt->close();
            }
        }
    }
    
    if ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        $contact_name = '';
        
        if ($id > 0) {
            // Get contact name for message
            $res = $mysqli->prepare("SELECT contact_name FROM tbl_contacts WHERE id = ?");
            $res->bind_param("i", $id);
            $res->execute();
            $res->bind_result($contact_name);
            $res->fetch();
            $res->close();
            
            // Delete contact
            $del = $mysqli->prepare("DELETE FROM tbl_contacts WHERE id = ?");
            $del->bind_param("i", $id);
            $del->execute();
            $del->close();
            
            redirect_with(['msg' => "Contact '$contact_name' deleted", 'type' => 'warning']);
        }
    }
    
    redirect_with();
}

// Get mode
$mode = $_GET['mode'] ?? 'view';
$editContact = null;

if ($mode === 'edit') {
    $id = (int)($_GET['id'] ?? 0);
    if ($id > 0) {
        $stmt = $mysqli->prepare("SELECT * FROM tbl_contacts WHERE id = ?");
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $editContact = $stmt->get_result()->fetch_assoc();
        $stmt->close();
    }
    if (!$editContact) $mode = 'view';
}

// Get search and filter parameters
$q = trim($_GET['q'] ?? '');
$sortBy = $_GET['sort_by'] ?? 'contact_name';
$sortOrder = $_GET['sort_order'] ?? 'ASC';

// Validate sort column
$allowedSorts = ['id', 'contact_name', 'contact_no', 'bank', 'created_at'];
if (!in_array($sortBy, $allowedSorts)) {
    $sortBy = 'contact_name';
}

// Validate sort order
if ($sortOrder !== 'ASC' && $sortOrder !== 'DESC') {
    $sortOrder = 'ASC';
}

// Build query
$sql = "SELECT * FROM tbl_contacts WHERE 1=1";

$params = [];
$types = '';

if ($q !== '') {
    $sql .= " AND (contact_name LIKE ? OR contact_no LIKE ? OR description LIKE ? OR bank LIKE ?)";
    $like = "%$q%";
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
    $types .= 'ssss';
}

$sql .= " ORDER BY $sortBy $sortOrder";

// Execute query
if (!empty($types)) {
    $stmt = $mysqli->prepare($sql);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $contacts = $stmt->get_result();
} else {
    $contacts = $mysqli->query($sql);
}

// Get total count
$totalContacts = $mysqli->query("SELECT COUNT(*) as cnt FROM tbl_contacts")->fetch_assoc()['cnt'];
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Contact Management</title>
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
            font-size: 12px;
            white-space: nowrap;
        }
        .table td {
            vertical-align: middle;
            font-size: 13px;
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
            <h4><i class="fas fa-address-book me-2"></i><?= $mode === 'edit' ? 'Edit Contact' : 'Add New Contact' ?></h4>
            <a href="contacts.php" class="btn btn-secondary btn-sm">
                <i class="fas fa-arrow-left me-1"></i> Back to List
            </a>
        </div>

        <div class="card">
            <div class="card-body">
                <form method="post" autocomplete="off">
                    <input type="hidden" name="action" value="<?= $mode ?>">
                    <?php if($mode === 'edit'): ?>
                    <input type="hidden" name="id" value="<?= (int)($editContact['id'] ?? 0) ?>">
                    <?php endif; ?>

                    <div class="row">
                        <!-- Row 1: Contact Details -->
                        <div class="col-md-4">
                            <div class="mb-3">
                                <label for="contact_name" class="form-label">Contact Name <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" id="contact_name" name="contact_name" 
                                       value="<?= h($editContact['contact_name'] ?? '') ?>" 
                                       placeholder="Enter Name" required>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="mb-3">
                                <label for="contact_no" class="form-label">Contact No.</label>
                                <input type="text" class="form-control" id="contact_no" name="contact_no"
                                       value="<?= h($editContact['contact_no'] ?? '') ?>" 
                                       placeholder="Enter Contact Number">
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="mb-3">
                                <label for="alternate_no" class="form-label">Alternate No.</label>
                                <input type="text" class="form-control" id="alternate_no" name="alternate_no"
                                       value="<?= h($editContact['alternate_no'] ?? '') ?>" 
                                       placeholder="Enter Alternative Number">
                            </div>
                        </div>
                    </div>

                    <div class="row">
                        <!-- Row 2: Email and Description side-by-side -->
                        <div class="col-md-4">
                            <div class="mb-3">
                                <label for="email" class="form-label">Email</label>
                                <input type="email" class="form-control" id="email" name="email"
                                       value="<?= h($editContact['email'] ?? '') ?>" 
                                       placeholder="Enter Email">
                            </div>
                        </div>
                        <div class="col-md-8">
                            <div class="mb-3">
                                <label for="description" class="form-label">Description</label>
                                <!-- Changed to input or single-row textarea to reduce size -->
                                <textarea class="form-control" id="description" name="description" rows="1" 
                                          placeholder="Enter Description"><?= h($editContact['description'] ?? '') ?></textarea>
                            </div>
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-md-4">
                            <div class="mb-3">
                                <label for="bank" class="form-label">Bank</label>
                                <input type="text" class="form-control" id="bank" name="bank"
                                       value="<?= h($editContact['bank'] ?? '') ?>" 
                                       placeholder="Enter Bank Name">
                            </div>
                        </div>
                        <!-- NEW BRANCH FIELD -->
                        <div class="col-md-3">
                            <div class="mb-3">
                                <label for="bank_branch" class="form-label">Bank Branch</label>
                                <input type="text" class="form-control" id="bank_branch" name="bank_branch"
                                       value="<?= h($editContact['bank_branch'] ?? '') ?>" 
                                       placeholder="Enter Branch Name">
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="mb-3">
                                <label for="account_no" class="form-label">Account No.</label>
                                <input type="text" class="form-control" id="account_no" name="account_no"
                                       value="<?= h($editContact['account_no'] ?? '') ?>" 
                                       placeholder="Enter Account Number">
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="mb-3">
                                <label for="ifsc_code" class="form-label">IFSC Code</label>
                                <input type="text" class="form-control" id="ifsc_code" name="ifsc_code"
                                       value="<?= h($editContact['ifsc_code'] ?? '') ?>" 
                                       placeholder="Enter IFSC Code">
                            </div>
                        </div>
                    </div>

                    <div class="d-flex gap-2 mt-4">
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-save me-1"></i>
                            <?= $mode === 'edit' ? 'Update Contact' : 'Save Contact' ?>
                        </button>
                        <a href="contacts.php" class="btn btn-outline-secondary">Cancel</a>
                    </div>
                </form>
            </div>
        </div>

        <?php else: ?>

        <!-- View Mode -->
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h4><i class="fas fa-address-book me-2"></i>Contact Management</h4>
            <a href="contacts.php?mode=add" class="btn btn-success btn-sm">
                <i class="fas fa-plus me-1"></i> Add Contact
            </a>
        </div>

        <!-- Search and Filter Section (Language Master Style) -->
        <div class="card mb-3">
            <div class="card-body">
                <form method="get" class="row g-3">
                    <div class="col-md-3">
                        <input type="text" name="q" class="form-control" 
                               placeholder="Search contacts..." value="<?= h($q) ?>">
                    </div>
                    <div class="col-md-3">
                        <select name="sort_by" class="form-select">
                            <option value="contact_name" <?= $sortBy === 'contact_name' ? 'selected' : '' ?>>Sort by Name</option>
                            <option value="contact_no" <?= $sortBy === 'contact_no' ? 'selected' : '' ?>>Sort by Phone</option>
                            <option value="bank" <?= $sortBy === 'bank' ? 'selected' : '' ?>>Sort by Bank</option>
                            <option value="created_at" <?= $sortBy === 'created_at' ? 'selected' : '' ?>>Sort by Date</option>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <select name="sort_order" class="form-select">
                            <option value="ASC" <?= $sortOrder === 'ASC' ? 'selected' : '' ?>>Ascending</option>
                            <option value="DESC" <?= $sortOrder === 'DESC' ? 'selected' : '' ?>>Descending</option>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-search me-1"></i> Search
                        </button>
                        <a href="contacts.php" class="btn btn-secondary ms-2">
                            <i class="fas fa-times me-1"></i> Clear
                        </a>
                    </div>
                </form>
            </div>
        </div>

        <!-- Contacts Table -->
        <div class="card">
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>ID</th>
                                <th>Contact Name</th>
                                <th>Contact No.</th>
                                <th>Description</th>
                                <th>Alternate No.</th>
                                <th>Bank</th>
                                <th>Account No.</th>
                                <th>IFSC Code</th>
                                <th width="120" class="text-center">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if($contacts && $contacts->num_rows > 0): ?>
                                <?php while($row = $contacts->fetch_assoc()): ?>
                                <tr>
                                    <td><?= (int)$row['id'] ?></td>
                                    <td class="fw-semibold"><?= h($row['contact_name']) ?></td>
                                    <td><?= h($row['contact_no']) ?></td>
                                    <td><small><?= h($row['description']) ?></small></td>
                                    <td><?= h($row['alternate_no']) ?></td>
                                    <td><?= h($row['bank']) ?></td>
                                    <td><?= h($row['account_no']) ?></td>
                                    <td><code><?= h($row['ifsc_code']) ?></code></td>
                                    <td class="text-center">
                                        <div class="btn-group btn-group-sm" role="group">
                                            <a href="contacts.php?mode=edit&id=<?= (int)$row['id'] ?>" 
                                               class="btn btn-warning" title="Edit">
                                                <i class="fas fa-edit"></i>
                                            </a>
                                            <form method="post" style="display:inline;" 
                                                  onsubmit="return confirm('Delete contact <?= h($row['contact_name']) ?>?')">
                                                <input type="hidden" name="action" value="delete">
                                                <input type="hidden" name="id" value="<?= (int)$row['id'] ?>">
                                                <button type="submit" class="btn btn-danger" title="Delete">
                                                    <i class="fas fa-trash"></i>
                                                </button>
                                            </form>
                                        </div>
                                    </td>
                                </tr>
                                <?php endwhile; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="9" class="text-center py-5">
                                        <div class="text-muted">
                                            <i class="fas fa-address-book fa-3x mb-3"></i>
                                            <h5>No contacts found</h5>
                                            <p class="mb-0">Add your business contacts here.</p>
                                            <a href="contacts.php?mode=add" class="btn btn-primary mt-2">
                                                <i class="fas fa-plus me-1"></i> Add Contact
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
        document.addEventListener('DOMContentLoaded', function() {
            // Auto-dismiss alerts after 5 seconds
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
    </script>
</body>
</html>
