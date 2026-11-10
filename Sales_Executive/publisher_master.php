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

function formatDate($d) {
    if (empty($d) || $d === '0000-00-00' || $d === '0000-00-00 00:00:00') return '—';
    return date('d-m-Y', strtotime($d));
}

function redirect_with($params =[]) {
    $base = 'publisher_master.php';
    if (!empty($params)) $base .= '?' . http_build_query($params);
    header("Location: $base");
    exit;
}

// Include SimpleXLSX library for import
$libPath = dirname(__DIR__) . '/simplexlsx-master/src/SimpleXLSX.php';
if (!file_exists($libPath)) $libPath = dirname(__DIR__) . '/simplexlsx-master/SimpleXLSX.php';
if (!file_exists($libPath)) $libPath = 'simplexlsx-master/src/SimpleXLSX.php';
if (!file_exists($libPath)) $libPath = 'simplexlsx-master/SimpleXLSX.php';

// Handle Excel Import
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'import_excel') {
    
    if (isset($_FILES['excel_file']) && $_FILES['excel_file']['error'] === 0) {
        
        // Check if library exists
        if (!file_exists($libPath)) {
            redirect_with(['msg' => 'SimpleXLSX library not found. Please install it.', 'type' => 'danger']);
        }
        
        require_once $libPath;
        $xlsx = Shuchkin\SimpleXLSX::parse($_FILES['excel_file']['tmp_name']);

        if ($xlsx) {
            $rows = $xlsx->rows();
            
            // Debug Counters
            $count_added = 0;
            $count_updated = 0;
            $errors = [];
            $updated_names = []; // Array to store updated publisher names

            foreach ($rows as $k => $r) {
                if ($k === 0) continue; // Skip Header row

                // Get data from Excel columns in the new order specified
                $sno = trim($mysqli->real_escape_string($r[0] ?? '')); 
                $country = trim($mysqli->real_escape_string($r[1] ?? '')); 
                $publisher_name = trim($mysqli->real_escape_string($r[2] ?? '')); 
                $contact_no = trim($mysqli->real_escape_string($r[3] ?? '')); 
                $email = trim($mysqli->real_escape_string($r[4] ?? '')); 
                $contact_person = trim($mysqli->real_escape_string($r[5] ?? '')); 
                $designation = trim($mysqli->real_escape_string($r[6] ?? '')); 
                $link = trim($mysqli->real_escape_string($r[7] ?? '')); 
                $status_raw = strtolower(trim($mysqli->real_escape_string($r[8] ?? 'active'))); 
                
                // Validate status
                $status = ($status_raw === 'inactive') ? 'inactive' : 'active';

                if (empty($publisher_name)) {
                    $errors[] = "Row " . ($k + 1) . ": Publisher Name is required";
                    continue;
                }

                // Check if publisher already exists
                $check_stmt = $mysqli->prepare("SELECT id FROM publisher_master WHERE publisher_name = ?");
                $check_stmt->bind_param("s", $publisher_name);
                $check_stmt->execute();
                $check_stmt->store_result();
                
                if ($check_stmt->num_rows > 0) {
                    // UPDATE existing record
                    $check_stmt->close();
                    
                    $stmt = $mysqli->prepare("UPDATE publisher_master SET 
                        country = ?, contact_no = ?, email = ?, contact_person = ?, 
                        designation = ?, link = ?, status = ?, updated_at = NOW() 
                        WHERE publisher_name = ?");
                    
                    $stmt->bind_param("ssssssss", 
                        $country, $contact_no, $email, $contact_person,
                        $designation, $link, $status, $publisher_name);
                    
                    if ($stmt->execute()) {
                        $count_updated++;
                        $updated_names[] = $publisher_name; // NEW: Save the name
                    } else {
                        $errors[] = "Row " . ($k + 1) . ": Update error - " . $stmt->error;
                    }
                    $stmt->close();
                } else {
                    // INSERT new record
                    $check_stmt->close();
                    
                    $stmt = $mysqli->prepare("INSERT INTO publisher_master 
                        (publisher_name, country, contact_no, email, contact_person, designation, link, status, created_at) 
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())");
                    
                    $stmt->bind_param("ssssssss", 
                        $publisher_name, $country, $contact_no, $email, 
                        $contact_person, $designation, $link, $status);
                    
                    if ($stmt->execute()) {
                        $count_added++;
                    } else {
                        $errors[] = "Row " . ($k + 1) . ": Insert error - " . $stmt->error;
                    }
                    $stmt->close();
                }
            }
            
            // Prepare result message
            $message = "Import completed: ";
            if ($count_added > 0) {
                $message .= "<strong>$count_added</strong> new publishers added. ";
            }
            if ($count_updated > 0) {
                // NEW: Show the names of updated publishers
                $names_str = implode(", ", $updated_names);
                $message .= "<strong>$count_updated</strong> existing publishers updated: <em>($names_str)</em>. ";
            }
            
            if (!empty($errors)) {
                $message .= "Errors: " . implode("; ", array_slice($errors, 0, 3));
                if (count($errors) > 3) {
                    $message .= "... (and " . (count($errors) - 3) . " more)";
                }
                redirect_with(['msg' => $message, 'type' => 'warning']);
            } else {
                redirect_with(['msg' => $message, 'type' => 'success']);
            }
            
        } else {
            redirect_with(['msg' => 'Failed to parse Excel file.', 'type' => 'danger']);
        }
    } else {
        $upload_error = $_FILES['excel_file']['error'] ?? 0;
        $error_messages =[
            UPLOAD_ERR_INI_SIZE => 'File size exceeds server limit.',
            UPLOAD_ERR_FORM_SIZE => 'File size exceeds form limit.',
            UPLOAD_ERR_PARTIAL => 'File was only partially uploaded.',
            UPLOAD_ERR_NO_FILE => 'No file was uploaded.',
            UPLOAD_ERR_NO_TMP_DIR => 'Missing temporary folder.',
            UPLOAD_ERR_CANT_WRITE => 'Failed to write file to disk.',
            UPLOAD_ERR_EXTENSION => 'A PHP extension stopped the file upload.',
        ];
        
        $error_msg = $error_messages[$upload_error] ?? 'Please select a file to upload.';
        redirect_with(['msg' => $error_msg, 'type' => 'danger']);
    }
}

// Handle POST actions for CRUD and Move to Client
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'add' || $action === 'edit') {
        $publisher_name = trim($_POST['publisher_name'] ?? '');
        $country = trim($_POST['country'] ?? '');
        $contact_no = trim($_POST['contact_no'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $contact_person = trim($_POST['contact_person'] ?? '');
        $designation = trim($_POST['designation'] ?? '');
        $link = trim($_POST['link'] ?? '');
        $status = $_POST['status'] ?? 'active';
        
        // Basic validation
        if ($publisher_name === '') {
            redirect_with(['msg' => 'Publisher Name is required.', 'type' => 'danger']);
        }
        
        if ($action === 'add') {
            // Check if publisher name already exists
            $check = $mysqli->prepare("SELECT id FROM publisher_master WHERE publisher_name = ?");
            $check->bind_param("s", $publisher_name);
            $check->execute();
            $check->store_result();
            
            if ($check->num_rows > 0) {
                $check->close();
                redirect_with(['msg' => 'Publisher Name already exists. Please use a different name.', 'type' => 'danger']);
            }
            $check->close();
            
            // Insert new publisher
            $stmt = $mysqli->prepare("INSERT INTO publisher_master 
                (publisher_name, country, contact_no, email, contact_person, designation, link, status, created_at, created_ip) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW(), ?)");
            
            $stmt->bind_param("sssssssss", 
                $publisher_name, $country, $contact_no, $email, $contact_person, 
                $designation, $link, $status, $_SERVER['REMOTE_ADDR']);
            
            if ($stmt->execute()) {
                redirect_with(['msg' => "Publisher '$publisher_name' added successfully", 'type' => 'success']);
            } else {
                redirect_with(['msg' => 'Error adding publisher: ' . $stmt->error, 'type' => 'danger']);
            }
            $stmt->close();
        } 
        elseif ($action === 'edit') {
            $id = (int)($_POST['id'] ?? 0);
            
            if ($id > 0) {
                // Check if publisher name already exists for another record
                $check = $mysqli->prepare("SELECT id FROM publisher_master WHERE publisher_name = ? AND id != ?");
                $check->bind_param("si", $publisher_name, $id);
                $check->execute();
                $check->store_result();
                
                if ($check->num_rows > 0) {
                    $check->close();
                    redirect_with(['msg' => 'Publisher Name already exists. Please use a different name.', 'type' => 'danger']);
                }
                $check->close();
                
                // Update publisher
                $sql = "UPDATE publisher_master SET 
                        publisher_name = ?, 
                        country = ?,
                        contact_no = ?,
                        email = ?,
                        contact_person = ?,
                        designation = ?,
                        link = ?,
                        status = ?,
                        updated_at = NOW(), 
                        updated_ip = ? 
                        WHERE id = ?";
                
                $stmt = $mysqli->prepare($sql);
                
                $stmt->bind_param("sssssssssi", 
                    $publisher_name, $country, $contact_no, $email, $contact_person,
                    $designation, $link, $status, $_SERVER['REMOTE_ADDR'], $id);
                
                if ($stmt->execute()) {
                    redirect_with(['msg' => "Publisher updated successfully", 'type' => 'success']);
                } else {
                    redirect_with(['msg' => 'Error updating publisher: ' . $stmt->error, 'type' => 'danger']);
                }
                $stmt->close();
            }
        }
    }

    if ($action === 'move_to_client') {
        $id = (int)($_POST['id'] ?? 0);
        
        if ($id > 0) {
            // 1. Fetch publisher data
            $stmt = $mysqli->prepare("SELECT publisher_name, country, contact_no, email, contact_person, designation, link FROM publisher_master WHERE id = ?");
            $stmt->bind_param("i", $id);
            $stmt->execute();
            $result = $stmt->get_result();
            
            if ($pub = $result->fetch_assoc()) {
                $stmt->close();
                
                // 2. Check if client already exists in client_master
                $check = $mysqli->prepare("SELECT client_id FROM client_master WHERE client_name = ? OR company_name = ?");
                $check->bind_param("ss", $pub['publisher_name'], $pub['publisher_name']);
                $check->execute();
                $check->store_result();
                
                if ($check->num_rows > 0) {
                    $check->close();
                    redirect_with(['msg' => "Client '{$pub['publisher_name']}' already exists in Client Master.", 'type' => 'warning']);
                }
                $check->close();
                
                // 3. Insert into client_master
                $insert = $mysqli->prepare("INSERT INTO client_master 
                    (client_name, company_name, contact_person, designation, email, phone, country, website, link, status, created_date) 
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'Active', NOW())");
                
                $insert->bind_param("sssssssss", 
                    $pub['publisher_name'],  // Maps to client_name
                    $pub['publisher_name'],  // Maps to company_name
                    $pub['contact_person'],  // Maps to contact_person
                    $pub['designation'],     // Maps to designation
                    $pub['email'],           // Maps to email
                    $pub['contact_no'],      // Maps to phone
                    $pub['country'],         // Maps to country
                    $pub['link'],            // Maps to website
                    $pub['link']             // Maps to link
                );
                
                if ($insert->execute()) {
                    // Optional: If you want to automatically DELETE from publisher_master after moving, uncomment below:
                    /*
                    $del = $mysqli->prepare("DELETE FROM publisher_master WHERE id = ?");
                    $del->bind_param("i", $id);
                    $del->execute();
                    $del->close();
                    */
                    
                    redirect_with(['msg' => "Publisher '{$pub['publisher_name']}' successfully copied to Client Master.", 'type' => 'success']);
                } else {
                    redirect_with(['msg' => 'Error moving to Client Master: ' . $insert->error, 'type' => 'danger']);
                }
                $insert->close();
            } else {
                $stmt->close();
                redirect_with(['msg' => 'Publisher not found.', 'type' => 'danger']);
            }
        }
    }
    
    if ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        $publisher_name = '';
        
        if ($id > 0) {
            // Get publisher name for message
            $res = $mysqli->prepare("SELECT publisher_name FROM publisher_master WHERE id = ?");
            $res->bind_param("i", $id);
            $res->execute();
            $res->bind_result($publisher_name);
            $res->fetch();
            $res->close();
            
            // Delete publisher
            $del = $mysqli->prepare("DELETE FROM publisher_master WHERE id = ?");
            $del->bind_param("i", $id);
            
            if ($del->execute()) {
                redirect_with(['msg' => "Publisher '$publisher_name' deleted successfully", 'type' => 'warning']);
            } else {
                redirect_with(['msg' => 'Error deleting publisher: ' . $del->error, 'type' => 'danger']);
            }
            $del->close();
        }
    }
    
    redirect_with();
}

// Get mode
$mode = $_GET['mode'] ?? 'view';
$editPublisher = null;

if ($mode === 'edit') {
    $id = (int)($_GET['id'] ?? 0);
    if ($id > 0) {
        $stmt = $mysqli->prepare("SELECT id, publisher_name, country, contact_no, email, contact_person, designation, link, status, created_at, created_ip, updated_at, updated_ip FROM publisher_master WHERE id = ?");
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $editPublisher = $stmt->get_result()->fetch_assoc();
        $stmt->close();
    }
    if (!$editPublisher) $mode = 'view';
}

// Get search and filter parameters
$q = trim($_GET['q'] ?? '');
$fstatus = $_GET['status'] ?? '';

// Build query
$sql = "SELECT id, publisher_name, country, contact_no, email, contact_person, designation, link, status, created_at, created_ip, updated_at, updated_ip FROM publisher_master WHERE 1=1";

$cond =[];
$params =[];
$types = '';

// UPDATED SEARCH LOGIC
if ($q !== '') {
    $cond[] = "(publisher_name LIKE ? OR contact_no LIKE ? OR contact_person LIKE ? OR email LIKE ? OR link LIKE ? OR country LIKE ?)";
    $like = "%$q%";
    $params[] = $like; // publisher_name
    $params[] = $like; // contact_no
    $params[] = $like; // contact_person
    $params[] = $like; // email
    $params[] = $like; // link
    $params[] = $like; // country (included as good measure)
    $types .= 'ssssss';
}

if ($fstatus !== '') {
    $cond[] = "status = ?";
    $params[] = $fstatus;
    $types .= 's';
}

if (!empty($cond)) {
    $sql .= " AND " . implode(" AND ", $cond);
}

$sql .= " ORDER BY created_at DESC";

// Execute query
if (!empty($types)) {
    $stmt = $mysqli->prepare($sql);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $publishers = $stmt->get_result();
} else {
    $publishers = $mysqli->query($sql);
}

// Get statistics
$totalPublishers = $mysqli->query("SELECT COUNT(*) as cnt FROM publisher_master")->fetch_assoc()['cnt'];
$activePublishers = $mysqli->query("SELECT COUNT(*) as cnt FROM publisher_master WHERE status = 'active'")->fetch_assoc()['cnt'];
$inactivePublishers = $mysqli->query("SELECT COUNT(*) as cnt FROM publisher_master WHERE status = 'inactive'")->fetch_assoc()['cnt'];
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Data Collection</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body {
            background-color: #f8f9fa;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }
        .main-content {
            padding: 20px;
            max-width: 1400px;
            margin: 0 auto;
        }
        .table th {
            background-color: #6c757d !important;
            color: white !important;
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
        .stats-container {
            display: flex;
            gap: 15px;
            margin-bottom: 20px;
            flex-wrap: wrap;
        }
        .stat-card {
            flex: 1;
            min-width: 150px;
            padding: 15px;
            border-radius: 8px;
            color: white;
            transition: transform 0.2s;
        }
        .stat-card:hover {
            transform: translateY(-2px);
        }
        .stat-card h3 {
            margin: 0;
            font-size: 32px;
            font-weight: bold;
        }
        .stat-card p {
            margin: 5px 0 0 0;
            font-size: 14px;
        }
        .stat-total { background: linear-gradient(135deg, #3498db, #2980b9); }
        .stat-active { background: linear-gradient(135deg, #27ae60, #229954); }
        .stat-inactive { background: linear-gradient(135deg, #95a5a6, #7f8c8d); }
        .btn-group-sm > .btn {
            padding: 0.25rem 0.5rem;
        }
        .template-download {
            background-color: #e7f3ff;
            border-left: 4px solid #0d6efd;
            padding: 15px;
            margin-bottom: 20px;
            border-radius: 5px;
        }
    </style>
</head>
<body>
    <?php require 'header.php'; ?>
    
    <div class="main-content" style="margin-top: 60px;">
        <?php if (!empty($_GET['msg'])): ?>
        <div class="alert alert-<?= h($_GET['type'] ?? 'info') ?> alert-dismissible fade show" role="alert">
            <?= h($_GET['msg']) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <?php endif; ?>

        <?php if($mode === 'add' || $mode === 'edit'): ?>
        
        <!-- Add/Edit Form -->
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h4><i class="fas fa-building me-2"></i><?= $mode === 'edit' ? 'Edit Publisher' : 'Add New Publisher' ?></h4>
            <a href="publisher_master.php" class="btn btn-secondary btn-sm">
                <i class="fas fa-arrow-left me-1"></i> Back to List
            </a>
        </div>

        <div class="card">
            <div class="card-body">
                <form method="post" autocomplete="off">
                    <input type="hidden" name="action" value="<?= $mode ?>">
                    <?php if($mode === 'edit'): ?>
                    <input type="hidden" name="id" value="<?= (int)($editPublisher['id'] ?? 0) ?>">
                    <?php endif; ?>

                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label for="publisher_name" class="form-label">Publisher Name <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" id="publisher_name" name="publisher_name"
                                       value="<?= h($editPublisher['publisher_name'] ?? '') ?>" 
                                       placeholder="Enter publisher name" required>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label for="country" class="form-label">Country</label>
                                <input type="text" class="form-control" id="country" name="country"
                                       value="<?= h($editPublisher['country'] ?? '') ?>" 
                                       placeholder="Enter country">
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label for="contact_no" class="form-label">Contact No.</label>
                                <input type="text" class="form-control" id="contact_no" name="contact_no"
                                       value="<?= h($editPublisher['contact_no'] ?? '') ?>" 
                                       placeholder="Enter contact number">
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label for="email" class="form-label">E-mail</label>
                                <input type="text" class="form-control" id="email" name="email"
                                       value="<?= h($editPublisher['email'] ?? '') ?>" 
                                       placeholder="Enter email address">
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label for="contact_person" class="form-label">Contact Person</label>
                                <input type="text" class="form-control" id="contact_person" name="contact_person"
                                       value="<?= h($editPublisher['contact_person'] ?? '') ?>" 
                                       placeholder="Enter contact person name">
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label for="designation" class="form-label">Designation</label>
                                <input type="text" class="form-control" id="designation" name="designation"
                                       value="<?= h($editPublisher['designation'] ?? '') ?>" 
                                       placeholder="Enter designation">
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label for="link" class="form-label">Link</label>
                                <input type="url" class="form-control" id="link" name="link"
                                       value="<?= h($editPublisher['link'] ?? '') ?>" 
                                       placeholder="Enter website link">
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label for="status" class="form-label">Status</label>
                                <select class="form-select" id="status" name="status">
                                    <option value="active" <?= ($editPublisher && $editPublisher['status'] == 'active') ? 'selected' : '' ?>>Active</option>
                                    <option value="inactive" <?= ($editPublisher && $editPublisher['status'] == 'inactive') ? 'selected' : '' ?>>Inactive</option>
                                </select>
                            </div>
                        </div>
                    </div>

                    <div class="d-flex gap-2 mt-4">
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-save me-1"></i>
                            <?= $mode === 'edit' ? 'Update Publisher' : 'Save Publisher' ?>
                        </button>
                        <a href="publisher_master.php" class="btn btn-outline-secondary">Cancel</a>
                    </div>
                </form>
            </div>
        </div>

        <?php else: ?>

        <!-- View Mode -->
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h4><i class="fas fa-building me-2"></i>Data Collection</h4>
            <div>
                  <button type="button" class="btn btn-info btn-sm text-white" data-bs-toggle="modal" data-bs-target="#importModal">
                    <i class="fas fa-file-import me-1"></i> Import Excel
                </button>

                <a href="publisher_master.php?mode=add" class="btn btn-success btn-sm me-2">
                    <i class="fas fa-plus me-1"></i> Add New
                </a>
                              <!--a href="client_followup.php" class="btn btn-primary btn-sm me-2">
                    <i class="fas fa-plus me-1"></i> Follow Ups
                </a-->
            </div>
        </div>

        <!-- Excel Import Template Download -->
        <div class="template-download">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <h6 class="mb-1"><i class="fas fa-file-excel me-2"></i>Excel Template</h6>
                    <p class="mb-0 text-muted">Download the Excel template with correct column format for import.</p>
                    <small class="text-muted">Required columns in order: S.No, Country, Publisher Name, Contact No, E-mail, Contact Person, Designation, Link, Status</small>
                </div>
            </div>
        </div>

        <!-- Statistics -->
        <div class="stats-container">
            <div class="stat-card stat-total">
                <h3><?= $totalPublishers ?></h3>
                <p><i class="fas fa-building me-1"></i> Total Publishers</p>
            </div>
            <div class="stat-card stat-active">
                <h3><?= $activePublishers ?></h3>
                <p><i class="fas fa-check-circle me-1"></i> Active</p>
            </div>
            <div class="stat-card stat-inactive">
                <h3><?= $inactivePublishers ?></h3>
                <p><i class="fas fa-times-circle me-1"></i> Inactive</p>
            </div>
        </div>

        <!-- Search and Filter Form -->
        <div class="card mb-4">
            <div class="card-body">
                <form method="get" class="row g-3 align-items-center">
                    <div class="col-md-6">
                        <!-- Updated placeholder to reflect new search capabilities -->
                        <input type="text" name="q" class="form-control form-control-sm" 
                               placeholder="Sort by Name, Contact No, Person, Email, Link..." value="<?= h($q) ?>">
                    </div>
                    <div class="col-md-4">
                        <select name="status" class="form-select form-select-sm">
                            <option value="">Sort by Status</option>
                            <option value="active" <?= $fstatus === 'active' ? 'selected' : '' ?>>Active</option>
                            <option value="inactive" <?= $fstatus === 'inactive' ? 'selected' : '' ?>>Inactive</option>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <button type="submit" class="btn btn-primary btn-sm me-2">
                            <i class="fas fa-search me-1"></i> Filter
                        </button>
                        <a href="publisher_master.php" class="btn btn-secondary btn-sm">
                            <i class="fas fa-times me-1"></i> Clear
                        </a>
                    </div>
                </form>
            </div>
        </div>

        <!-- Publishers Table in specified order: Country, Publisher Name, Contact No, E-mail, Contact Person, Designation, Link -->
        <div class="card">
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>S.No</th>
                                <th>Country</th>
                                <th>Publisher Name</th>
                                <th>Contact No.</th>
                                <th>E-mail</th>
                                <th>Contact Person</th>
                                <th>Designation</th>
                                <th>Link</th>
                                <th>Status</th>
                                <th width="120" class="text-center">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if($publishers && $publishers->num_rows > 0): $i=0;?>
                                <?php while($row = $publishers->fetch_assoc()): $i=$i+1;?>
                                <tr>
                                    <td><?= $i; ?></td>
                                    <td>
                                        <span class="fw-semibold"><?= h($row['country'] ?: '—') ?></span>
                                    </td>
                                    <td class="fw-bold"><?= h($row['publisher_name'] ?: '—') ?></td>
                                    <td>
                                        <?php if(!empty($row['contact_no'])): ?>
                                            <i class="fas fa-phone-alt text-success me-1 small"></i>
                                            <?= h($row['contact_no']) ?>
                                        <?php else: ?>
                                            —
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if(!empty($row['email'])): ?>
                                            <i class="fas fa-envelope text-info me-1 small"></i>
                                            <a href="mailto:<?= h($row['email']) ?>"><?= h($row['email']) ?></a>
                                        <?php else: ?>
                                            —
                                        <?php endif; ?>
                                    </td>
                                    <td><?= h($row['contact_person'] ?: '—') ?></td>
                                    <td><?= h($row['designation'] ?: '—') ?></td>
                                    <td>
                                        <?php if(!empty($row['link'])): ?>
                                            <a href="<?php echo "http://". h($row['link']) ?>" target="_blank" class="text-decoration-none">
                                                <i class="fas fa-external-link-alt me-1"></i> <?php echo h($row['link']) ?>
                                            </a>
                                        <?php else: ?>
                                            —
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php 
                                        $statusClass = ($row['status'] == 'active') ? 'bg-success' : 'bg-secondary';
                                        $statusText = ($row['status'] == 'active') ? 'Active' : 'Inactive';
                                        ?>
                                        <span class="badge <?= $statusClass ?>"><?= $statusText ?></span>
                                    </td>
                                    <td class="text-center">
                                        <div class="btn-group btn-group-sm" role="group">
                                            <!-- Edit Button -->
                                            <a href="publisher_master.php?mode=edit&id=<?= (int)$row['id'] ?>" 
                                               class="btn btn-warning" title="Edit">
                                                <i class="fas fa-edit"></i>
                                            </a>
                                            
                                            <!-- Move to Client Button -->
                                            <form method="post" style="display:inline;" 
                                                  onsubmit="return confirm('Are you sure you want to move publisher <?= h($row['publisher_name']) ?> to the Client Master?')">
                                                <input type="hidden" name="action" value="move_to_client">
                                                <input type="hidden" name="id" value="<?= (int)$row['id'] ?>">
                                                <button type="submit" class="btn btn-info text-white" title="Move to Client">
                                                    <i class="fas fa-user-tie"></i>
                                                </button>
                                            </form>

                                            <!-- Delete Button -->
                                            <form method="post" style="display:inline;" 
                                                  onsubmit="return confirm('Delete publisher <?= h($row['publisher_name']) ?>? This action cannot be undone.')">
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
                                    <td colspan="10" class="text-center py-5">
                                        <div class="text-muted">
                                            <i class="fas fa-building fa-3x mb-3"></i>
                                            <h5>No publishers found</h5>
                                            <p class="mb-0">Add publishers to your master list.</p>
                                            <a href="publisher_master.php?mode=add" class="btn btn-primary mt-3">
                                                <i class="fas fa-plus me-1"></i> Add First Publisher
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

    <!-- Import Excel Modal -->
    <div class="modal fade" id="importModal" tabindex="-1" aria-labelledby="importModalLabel" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header bg-info text-white">
                    <h5 class="modal-title" id="importModalLabel">
                        <i class="fas fa-file-import me-2"></i>Import Publishers from Excel
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form method="post" enctype="multipart/form-data">
                    <input type="hidden" name="action" value="import_excel">
                    <div class="modal-body">
                        
                        <div class="d-flex justify-content-between align-items-end mb-3">
                            <div class="flex-grow-1 me-3">
                                <label for="excel_file" class="form-label fw-bold">Select Excel File <span class="text-danger">*</span></label>
                                <input type="file" class="form-control" id="excel_file" name="excel_file" accept=".xlsx,.xls" required>
                            </div>
                            <div>
                                <button type="button" class="btn btn-success" onclick="downloadSample()">
                                    <i class="fas fa-download me-1"></i> Sample Format
                                </button>
                            </div>
                        </div>
                        
                        <div class="alert alert-info mt-3">
                            <h6><i class="fas fa-info-circle me-2"></i>Instructions:</h6>
                            <ul class="mb-0 small">
                                <li>Excel file must have these columns IN ORDER:</li>
                                <li>Column A: <strong>S.No</strong></li>
                                <li>Column B: <strong>Country</strong></li>
                                <li>Column C: <strong>Publisher Name</strong> (required)</li>
                                <li>Column D: <strong>Contact No</strong></li>
                                <li>Column E: <strong>E-mail</strong></li>
                                <li>Column F: <strong>Contact Person</strong></li>
                                <li>Column G: <strong>Designation</strong></li>
                                <li>Column H: <strong>Link</strong></li>
                                <li>Column I: <strong>Status</strong> (active/inactive)</li>
                                <li>First row should be headers (will be skipped)</li>
                                <li>Existing publishers will be updated based on Publisher Name</li>
                            </ul>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-info text-white">
                            <i class="fas fa-upload me-1"></i> Import Data
                        </button>
                    </div>
                </form>
            </div>
        </div>
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

            // Auto-submit for filter selects
            const searchForm = document.querySelector('form[method="get"]');
            if (searchForm) {
                const filterSelects = searchForm.querySelectorAll('select[name="status"]');
                filterSelects.forEach(select => {
                    select.addEventListener('change', function() {
                        searchForm.submit();
                    });
                });
            }
        });

        // Function to download the sample Excel (CSV) format
        function downloadSample() {
            const headers = "S.No,Country,Publisher Name,Contact No,E-mail,Contact Person,Designation,Link,Status\n";
            const sampleData = "1,India,Tech Publishing House,9876543210,contact@techpub.com,John Doe,Manager,techpub.com,active\n";
            
            const blob = new Blob([headers + sampleData], { type: 'text/csv' });
            const url = window.URL.createObjectURL(blob);
            const a = document.createElement('a');
            a.href = url;
            a.download = 'publisher_sample_import.csv';
            document.body.appendChild(a);
            a.click();
            document.body.removeChild(a);
            window.URL.revokeObjectURL(url);
        }
    </script>
</body>
</html>