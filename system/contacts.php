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

// Refactored to use sessions for messages to prevent URL-based XSS
function redirect_with($params = []) {
    if (isset($params['msg'])) {
        $_SESSION['flash_msg'] = $params['msg'];
        $_SESSION['flash_type'] = $params['type'] ?? 'info';
        unset($params['msg'], $params['type']);
    }
    $base = 'contacts.php';
    if (!empty($params)) $base .= '?' . http_build_query($params);
    header("Location: $base");
    exit;
}

// ---------------------------------------------------------
// HANDLE IMPORT LOGIC (XLSX Format)
// ---------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'import_excel') {
    
    if (isset($_FILES['excel_file']) && $_FILES['excel_file']['error'] === 0) {
        
        $libPath = dirname(__DIR__) . '/simplexlsx-master/src/SimpleXLSX.php';
        if (!file_exists($libPath)) $libPath = dirname(__DIR__) . '/simplexlsx-master/SimpleXLSX.php';
        if (!file_exists($libPath)) $libPath = 'simplexlsx-master/src/SimpleXLSX.php';

        if (!file_exists($libPath)) {
            redirect_with(['msg' => 'Library file not found.', 'type' => 'danger']);
        }
        
        require_once $libPath;

        $xlsx = null;
        if (class_exists('Shuchkin\SimpleXLSX')) {
            $xlsx = Shuchkin\SimpleXLSX::parse($_FILES['excel_file']['tmp_name']);
        } elseif (class_exists('SimpleXLSX')) {
            $xlsx = SimpleXLSX::parse($_FILES['excel_file']['tmp_name']);
        } else {
            redirect_with(['msg' => 'Class SimpleXLSX not found.', 'type' => 'danger']);
        }

        if ($xlsx) {
            $rows = $xlsx->rows();
            $imported_count = 0;
            $updated_count = 0;
            $skipped_count = 0;
            $error_details =[];
            
            foreach ($rows as $k => $r) {
                if ($k === 0) continue; // Skip Header Row

                // --- UPDATED COLUMN MAPPING ---
                // 0: Company, 1: Contact Name, 2: Description, 3: Contact No, 4: Alternate, 5: Email, 6: Bank, 7: Branch, 8: IFSC, 9: Account No, 10: Remarks
                
                $company_name = trim($r[0] ?? '');
                $contact_name = trim($r[1] ?? '');
                $description  = trim($r[2] ?? '');
                $contact_no   = trim($r[3] ?? '');
                $alternate_no = trim($r[4] ?? '');
                $email        = trim($r[5] ?? '');
                $bank         = trim($r[6] ?? '');
                $bank_branch  = trim($r[7] ?? '');
                $ifsc_code    = trim($r[8] ?? '');
                $account_no   = trim($r[9] ?? '');
                $remarks      = trim($r[10] ?? ''); // Added Remarks
                
                if (empty($contact_name)) {
                    $skipped_count++;
                    $error_details[] = "Row " . ($k + 1) . ": Missing Contact Name";
                    continue;
                }

                $checkStmt = $mysqli->prepare("SELECT id FROM tbl_contacts WHERE contact_name = ?");
                $checkStmt->bind_param("s", $contact_name);
                $checkStmt->execute();
                $exists = $checkStmt->get_result()->num_rows > 0;
                $checkStmt->close();

                if (!$exists) {
                    $insertStmt = $mysqli->prepare("INSERT INTO tbl_contacts 
                        (company_name, contact_name, contact_no, alternate_no, email, description, bank, bank_branch, account_no, ifsc_code, remarks, created_at, created_ip) 
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), ?)");
                    
                    $insertStmt->bind_param("ssssssssssss", 
                        $company_name, $contact_name, $contact_no, $alternate_no, $email, $description, 
                        $bank, $bank_branch, $account_no, $ifsc_code, $remarks, $_SERVER['REMOTE_ADDR']
                    );
                    
                    if ($insertStmt->execute()) {
                        $imported_count++;
                    } else {
                        $skipped_count++;
                        $error_details[] = "Row " . ($k + 1) . ": " . $mysqli->error;
                    }
                    $insertStmt->close();
                } else {
                    $updateStmt = $mysqli->prepare("UPDATE tbl_contacts SET 
                        company_name = ?, contact_no = ?, alternate_no = ?, email = ?, description = ?, 
                        bank = ?, bank_branch = ?, account_no = ?, ifsc_code = ?, remarks = ?,
                        updated_at = NOW(), updated_ip = ? 
                        WHERE contact_name = ?");
                    
                    $updateStmt->bind_param("ssssssssssss", 
                        $company_name, $contact_no, $alternate_no, $email, $description, 
                        $bank, $bank_branch, $account_no, $ifsc_code, $remarks,
                        $_SERVER['REMOTE_ADDR'], $contact_name
                    );
                    
                    if ($updateStmt->execute()) {
                        $updated_count++;
                    }
                    $updateStmt->close();
                }
            }
            
            $msg = "Process Complete.<br>";
            $msg .= "<strong>Imported:</strong> $imported_count new contacts.<br>";
            $msg .= "<strong>Updated:</strong> $updated_count existing contacts.<br>";
            $msg .= "<strong>Skipped:</strong> $skipped_count rows.";
            
            if (!empty($error_details)) {
                $msg .= "<br><small class='text-danger'>" . implode("<br>", array_slice($error_details, 0, 5)) . "</small>";
            }
            
            $type = ($imported_count > 0 || $updated_count > 0) ? 'success' : 'warning';
            redirect_with(['msg' => $msg, 'type' => $type]);
        } else {
            $err = (method_exists('Shuchkin\SimpleXLSX', 'parseError')) ? Shuchkin\SimpleXLSX::parseError() : 'Unknown Error';
            redirect_with(['msg' => 'SimpleXLSX Parse Error: ' . $err, 'type' => 'danger']);
        }
    } else {
        redirect_with(['msg' => 'No file uploaded or file error.', 'type' => 'danger']);
    }
}

// Logic for Add/Edit/Delete
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if ($action === 'add' || $action === 'edit') {
        $company_name = trim($_POST['company_name'] ?? '');
        $contact_name = trim($_POST['contact_name'] ?? '');
        $contact_no = trim($_POST['contact_no'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $alternate_no = trim($_POST['alternate_no'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $bank = trim($_POST['bank'] ?? '');
        $bank_branch = trim($_POST['bank_branch'] ?? '');
        $account_no = trim($_POST['account_no'] ?? '');
        $ifsc_code = trim($_POST['ifsc_code'] ?? '');
        $remarks = trim($_POST['remarks'] ?? ''); // Added Remarks
        
        if ($contact_name === '') {
            redirect_with(['msg' => 'Contact Name is required.', 'type' => 'danger']);
        }
        
        if ($action === 'add') {
            $stmt = $mysqli->prepare("INSERT INTO tbl_contacts (company_name, contact_name, contact_no, description, alternate_no, email, bank, bank_branch, account_no, ifsc_code, remarks, created_at, created_ip) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), ?)");
            $stmt->bind_param("ssssssssssss", $company_name, $contact_name, $contact_no, $description, $alternate_no, $email, $bank, $bank_branch, $account_no, $ifsc_code, $remarks, $_SERVER['REMOTE_ADDR']);
            if ($stmt->execute()) redirect_with(['msg' => "Contact '$contact_name' added successfully", 'type' => 'success']);
            $stmt->close();
        } 
        elseif ($action === 'edit') {
            $id = (int)($_POST['id'] ?? 0);
            if ($id > 0) {
                $sql = "UPDATE tbl_contacts SET company_name=?, contact_name=?, contact_no=?, description=?, alternate_no=?, email=?, bank=?, bank_branch=?, account_no=?, ifsc_code=?, remarks=?, updated_at=NOW(), updated_ip=? WHERE id=?";
                $stmt = $mysqli->prepare($sql);
                $stmt->bind_param("ssssssssssssi", $company_name, $contact_name, $contact_no, $description, $alternate_no, $email, $bank, $bank_branch, $account_no, $ifsc_code, $remarks, $_SERVER['REMOTE_ADDR'], $id);
                if ($stmt->execute()) redirect_with(['msg' => "Contact updated successfully", 'type' => 'success']);
                $stmt->close();
            }
        }
    }
    if ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id > 0) {
            $del = $mysqli->prepare("DELETE FROM tbl_contacts WHERE id = ?");
            $del->bind_param("i", $id);
            $del->execute();
            redirect_with(['msg' => "Contact deleted", 'type' => 'warning']);
        }
    }
    if ($action !== 'import_excel') redirect_with();
}

// Fetch logic for View Mode
$mode = $_GET['mode'] ?? 'view';
$editContact = null;
if ($mode === 'edit') {
    $id = (int)($_GET['id'] ?? 0);
    $stmt = $mysqli->prepare("SELECT * FROM tbl_contacts WHERE id = ?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $editContact = $stmt->get_result()->fetch_assoc();
    if (!$editContact) $mode = 'view';
}

$q = trim($_GET['q'] ?? '');

// Whitelisted Sorting parameters to prevent SQL Injection
$allowed_columns =['id', 'company_name', 'contact_name', 'description', 'contact_no', 'bank', 'bank_branch', 'ifsc_code', 'account_no', 'remarks'];
$sortBy = in_array($_GET['sort_by'] ?? '', $allowed_columns) ? $_GET['sort_by'] : 'contact_name';
$sortOrder = strtoupper($_GET['sort_order'] ?? '') === 'DESC' ? 'DESC' : 'ASC';

$sql = "SELECT * FROM tbl_contacts WHERE 1=1";
if ($q !== '') {
    // Added Remarks to Search logic
    $sql .= " AND (company_name LIKE ? OR contact_name LIKE ? OR contact_no LIKE ? OR description LIKE ? OR bank LIKE ? OR remarks LIKE ?)";
    $like = "%$q%";
    $stmt = $mysqli->prepare($sql . " ORDER BY $sortBy $sortOrder");
    $stmt->bind_param("ssssss", $like, $like, $like, $like, $like, $like);
    $stmt->execute();
    $contacts = $stmt->get_result();
} else {
    $contacts = $mysqli->query($sql . " ORDER BY $sortBy $sortOrder");
}
$totalContacts = $mysqli->query("SELECT COUNT(*) as cnt FROM tbl_contacts")->fetch_assoc()['cnt'];
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Contacts Management</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body { background-color: #f8f9fa; font-family: 'Segoe UI', sans-serif; }
        .main-content { padding: 20px; max-width: 1800px; margin: 0 auto; }
        .table th { background-color: #2c3e50; color: white; font-size: 12px; }
        .table td { font-size: 13px; }
    </style>
</head>
<body>
    <?php require 'header.php'; ?>
    
    <div class="main-content">
        <!-- Render Secure Flash Messages -->
        <?php if (!empty($_SESSION['flash_msg'])): ?>
            <div class="alert alert-<?= h($_SESSION['flash_type'] ?? 'info') ?> alert-dismissible fade show">
                <?= $_SESSION['flash_msg'] ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
            <?php unset($_SESSION['flash_msg'], $_SESSION['flash_type']); ?>
        <?php endif; ?>

        <?php if($mode === 'add' || $mode === 'edit'): ?>
            <div class="d-flex justify-content-between align-items-center mb-4">
                <h4><?= $mode === 'edit' ? 'Edit Contact' : 'Add New Contact' ?></h4>
                <a href="contacts.php" class="btn btn-secondary btn-sm">Back to List</a>
            </div>
            <div class="card"><div class="card-body">
                <form method="post">
                    <input type="hidden" name="action" value="<?= $mode ?>">
                    <?php if($mode === 'edit'): ?><input type="hidden" name="id" value="<?= (int)$editContact['id'] ?>"><?php endif; ?>
                    <div class="row">
                        <div class="col-md-3 mb-3"><label>Company Name</label><input type="text" name="company_name" class="form-control" value="<?= h($editContact['company_name'] ?? '') ?>"></div>
                        <div class="col-md-3 mb-3"><label>Contact Name *</label><input type="text" name="contact_name" class="form-control" value="<?= h($editContact['contact_name'] ?? '') ?>" required></div>
                        <div class="col-md-3 mb-3"><label>Contact No.</label><input type="text" name="contact_no" class="form-control" value="<?= h($editContact['contact_no'] ?? '') ?>"></div>
                        <div class="col-md-3 mb-3"><label>Alternate No.</label><input type="text" name="alternate_no" class="form-control" value="<?= h($editContact['alternate_no'] ?? '') ?>"></div>
                        
                        <div class="col-md-4 mb-3"><label>Email</label><input type="email" name="email" class="form-control" value="<?= h($editContact['email'] ?? '') ?>"></div>
                        <div class="col-md-8 mb-3"><label>Description</label><textarea name="description" class="form-control" rows="1"><?= h($editContact['description'] ?? '') ?></textarea></div>
                        
                        <div class="col-md-3 mb-3"><label>Bank</label><input type="text" name="bank" class="form-control" value="<?= h($editContact['bank'] ?? '') ?>"></div>
                        <div class="col-md-3 mb-3"><label>Bank Branch</label><input type="text" name="bank_branch" class="form-control" value="<?= h($editContact['bank_branch'] ?? '') ?>"></div>
                        <div class="col-md-3 mb-3"><label>IFSC Code</label><input type="text" name="ifsc_code" class="form-control" value="<?= h($editContact['ifsc_code'] ?? '') ?>"></div>
                        <div class="col-md-3 mb-3"><label>Account No.</label><input type="text" name="account_no" class="form-control" value="<?= h($editContact['account_no'] ?? '') ?>"></div>
                        
                        <div class="col-md-12 mb-3"><label>Remarks</label><input type="text" name="remarks" class="form-control" value="<?= h($editContact['remarks'] ?? '') ?>"></div>
                    </div>
                    <button type="submit" class="btn btn-primary">Save Contact</button>
                </form>
            </div></div>

        <?php else: ?>
            <div class="d-flex justify-content-between align-items-center mb-4">
                <div><h4>Contact Management</h4><p class="text-muted"><?= $totalContacts ?> Total Contacts</p></div>
                <div>
                    <button class="btn btn-warning btn-sm" data-bs-toggle="modal" data-bs-target="#importModal"><i class="fas fa-file-excel me-2"></i> Import Excel</button>
                    <a href="contacts.php?mode=add" class="btn btn-success btn-sm"><i class="fas fa-plus me-1"></i> Add Contact</a>
                </div>
            </div>

            <div class="card mb-3"><div class="card-body">
                <form method="get" class="row g-3">
                    <div class="col-md-4"><input type="text" name="q" class="form-control" placeholder="Search..." value="<?= h($q) ?>"></div>
                    <div class="col-md-2"><button type="submit" class="btn btn-primary w-100">Search</button></div>
                </form>
            </div></div>

            <div class="card"><div class="table-responsive">
                <table class="table table-hover align-middle">
                    <thead>
                        <tr>
                            <th>S.No</th>
                            <th>Company</th>
                            <th>Contact Name</th>
                            <th>Description</th>
                            <th>Contact No</th>
                            <th>Bank</th>
                            <th>Branch</th>
                            <th>IFSC</th>
                            <th>Account No</th>
                            <th>Remarks</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php $i=1; while($row = $contacts->fetch_assoc()): ?>
                        <tr>
                            <td><?= $i++ ?></td>
                            <td><?= h($row['company_name']) ?></td>
                            <td class="fw-bold"><?= h($row['contact_name']) ?></td>
                            <td><small><?= h($row['description']) ?></small></td>
                            <td><?= h($row['contact_no']) ?></td>
                            <td><?= h($row['bank']) ?></td>
                            <td><?= h($row['bank_branch']) ?></td>
                            <td><code><?= h($row['ifsc_code']) ?></code></td>
                            <td><?= h($row['account_no']) ?></td>
                            <td><?= h($row['remarks']) ?></td>
                            <td class="text-nowrap">
                                <a href="contacts.php?mode=edit&id=<?= $row['id'] ?>" class="btn btn-sm btn-warning"><i class="fas fa-edit"></i></a>
                                <form method="post" class="d-inline" onsubmit="return confirm('Delete?')">
                                    <input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= $row['id'] ?>">
                                    <button class="btn btn-sm btn-danger"><i class="fas fa-trash"></i></button>
                                </form>
                            </td>
                        </tr>
                        <?php endwhile; ?>
                    </tbody>
                </table>
            </div></div>
        <?php endif; ?>
    </div>

    <!-- IMPORT MODAL -->
    <div class="modal fade" id="importModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header bg-warning">
                    <h5 class="modal-title">Import Contacts from Excel</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="post" enctype="multipart/form-data">
                    <input type="hidden" name="action" value="import_excel">
                    <div class="modal-body">
                        <div class="row">
                            <div class="col-md-8">
                                <label class="fw-bold">Select Excel File (.xlsx)</label>
                                <input type="file" name="excel_file" class="form-control" accept=".xlsx" required>
                            </div>
                            <div class="col-md-4">
                                <label class="fw-bold">Download</label>
                                <button type="button" class="btn btn-outline-success w-100" onclick="downloadSample()">Sample Format</button>
                            </div>
                        </div>
                        <div class="alert alert-danger mt-3 mb-0 pb-2">
                            <strong>Crucial Step:</strong> The sample downloads as a <code>.csv</code> file. You <strong>MUST</strong> open it in Excel, click <em>Save As</em>, and save it as an <strong>Excel Workbook (.xlsx)</strong> before importing.
                        </div>
                        <div class="alert alert-info mt-2">
                            <h6>Required Column Order:</h6>
                            <ol class="mb-0" style="column-count: 2;">
                                <li>Company Name</li>
                                <li>Contact Name (Mandatory)</li>
                                <li>Description</li>
                                <li>Contact No.</li>
                                <li>Alternate No.</li>
                                <li>Email</li>
                                <li>Bank</li>
                                <li>Bank Branch</li>
                                <li>IFSC Code</li>
                                <li>Account No.</li>
                                <li><strong>Remarks</strong></li>
                            </ol>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="submit" class="btn btn-dark">Import Data</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        function downloadSample() {
            // Updated header & sample row to include 'Remarks' at the end
            const headers = "Company Name,Contact Name,Description,Contact No.,Alternate No.,Email,Bank,Bank Branch,IFSC Code,Account No.,Remarks\n";
            const row1 = "Tech Solutions Ltd,John Doe,Software Vendor,9876543210,9876543211,john@example.com,HDFC,Mumbai,HDFC0001234,0987654321123,Premium Account";
            
            const blob = new Blob([headers + row1], { type: 'text/csv' });
            const url = window.URL.createObjectURL(blob);
            const a = document.createElement('a');
            a.href = url;
            a.download = 'contacts_sample.csv';
            document.body.appendChild(a);
            a.click();
            document.body.removeChild(a);
            
            alert("Important: The file downloaded is a CSV.\n\nThe system only accepts .xlsx files. Please open this file in Excel and 'Save As -> Excel Workbook (.xlsx)' before uploading.");
        }
    </script>
</body>
</html>