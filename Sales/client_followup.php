<?php
require 'db.php'; // Ensure database connection
ini_set('display_errors', 1);
error_reporting(E_ALL);
if (session_status() === PHP_SESSION_NONE) { session_start(); }

// Helpers
function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function formatDate($d) { return ($d && $d != '0000-00-00') ? date('d-m-Y', strtotime($d)) : '-'; }
function redirect_with($params =[]) {
    $base = 'client_followup.php';
    if (!empty($params)) $base .= '?' . http_build_query($params);
    echo "<script>window.location.href='$base';</script>";
    exit;
}

// ---------------------------------------------------------
// 0. HANDLE IMPORT LOGIC (XLSX Format)
// ---------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'import_excel') {
    if (isset($_FILES['excel_file']) && $_FILES['excel_file']['error'] === 0) {
        
        $libPath = dirname(__DIR__) . '/simplexlsx-master/src/SimpleXLSX.php';
        if (!file_exists($libPath)) $libPath = 'simplexlsx-master/src/SimpleXLSX.php';

        if (!file_exists($libPath)) {
            redirect_with(['msg' => 'SimpleXLSX Library not found at ' . $libPath, 'type' => 'danger']);
        }
        
        require_once $libPath;
        $xlsx = \Shuchkin\SimpleXLSX::parse($_FILES['excel_file']['tmp_name']);

        if ($xlsx) {
            $rows = $xlsx->rows();
            $imported_count = 0;
            $skipped_count = 0;
            $error_details =[];
            
            foreach ($rows as $k => $r) {
                if ($k === 0) continue; // Skip Header

                // Mapping: 0:Publisher Name, 1:Followup Date, 2:Next Followup Date, 3:Medium, 4:Status, 5:Remarks
                $excel_publisher_name  = trim($r[0] ?? '');
                $followup_date_raw     = trim($r[1] ?? '');
                $next_date_raw         = trim($r[2] ?? '');
                $comm_medium           = trim($r[3] ?? '');
                $followup_status       = trim($r[4] ?? '');
                $remarks               = trim($r[5] ?? '');

                if (empty($excel_publisher_name) || empty($remarks)) {
                    $skipped_count++;
                    continue;
                }

                // 1. Find Publisher ID
                $client_id = 0;
                $stmtP = $mysqli->prepare("SELECT id FROM publisher_master WHERE publisher_name = ? OR code = ? LIMIT 1");
                $stmtP->bind_param("ss", $excel_publisher_name, $excel_publisher_name);
                $stmtP->execute();
                $stmtP->bind_result($client_id);
                $stmtP->fetch();
                $stmtP->close();

                if (!$client_id) {
                    $error_details[] = "Row " . ($k+1) . ": Publisher '$excel_publisher_name' not found.";
                    $skipped_count++;
                    continue;
                }

                // 2. Date Conversion
                $f_date = date('Y-m-d'); // Default to today if empty
                if (!empty($followup_date_raw)) {
                    $f_date = is_numeric($followup_date_raw) ? date('Y-m-d', \Shuchkin\SimpleXLSX::unixstamp($followup_date_raw)) : date('Y-m-d', strtotime($followup_date_raw));
                }
                
                $n_date = NULL;
                if (!empty($next_date_raw)) {
                    $n_date = is_numeric($next_date_raw) ? date('Y-m-d', \Shuchkin\SimpleXLSX::unixstamp($next_date_raw)) : date('Y-m-d', strtotime($next_date_raw));
                }

                // 3. Insert Entry
                $ins = $mysqli->prepare("INSERT INTO client_followup_history (client_id, followup_date, next_followup_date, comm_medium, followup_status, remarks, created_at) VALUES (?, ?, ?, ?, ?, ?, NOW())");
                $ins->bind_param("isssss", $client_id, $f_date, $n_date, $comm_medium, $followup_status, $remarks);
                if ($ins->execute()) $imported_count++;
                else $skipped_count++;
                $ins->close();
            }
            
            $msg = "Imported $imported_count logs. Skipped $skipped_count rows.";
            if (!empty($error_details)) $msg .= " Errors: " . implode(" | ", array_slice($error_details, 0, 3));
            redirect_with(['msg' => $msg, 'type' => ($imported_count > 0 ? 'success' : 'warning')]);
        }
    }
}

// ---------------------------------------------------------
// 1. DASHBOARD STATISTICS
// ---------------------------------------------------------
$total_publishers = $mysqli->query("SELECT COUNT(*) FROM publisher_master")->fetch_row()[0] ?? 0;
$active_publishers = $mysqli->query("SELECT COUNT(*) FROM publisher_master WHERE status='Active'")->fetch_row()[0] ?? 0;
$today = date('Y-m-d');
$followup_today = $mysqli->query("SELECT COUNT(*) FROM client_followup_history WHERE followup_date = '$today'")->fetch_row()[0] ?? 0;
$overdue_followups = $mysqli->query("SELECT COUNT(DISTINCT client_id) FROM client_followup_history WHERE next_followup_date < '$today' AND next_followup_date IS NOT NULL")->fetch_row()[0] ?? 0;

// ---------------------------------------------------------
// 2. HANDLE AJAX REQUESTS (Edit Remark)
// ---------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'edit_remark_ajax') {
    $log_id = (int)$_POST['log_id'];
    $new_remark = trim($_POST['remark']);
    if ($log_id > 0) {
        $stmt = $mysqli->prepare("UPDATE client_followup_history SET remarks = ? WHERE id = ?");
        $stmt->bind_param("si", $new_remark, $log_id);
        if ($stmt->execute()) {
            echo "SUCCESS";
        } else {
            echo "ERROR";
        }
        $stmt->close();
    }
    exit;
}

// ---------------------------------------------------------
// 3. HANDLE AJAX REQUESTS (Fetch History)
// ---------------------------------------------------------
if (isset($_GET['fetch_history']) && isset($_GET['publisher_id'])) {
    $pid = (int)$_GET['publisher_id'];
    
    // Added 'id' to the SELECT query to enable editing
    $sql = "SELECT id, followup_date, next_followup_date, comm_medium, followup_status, remarks, created_at 
            FROM client_followup_history WHERE client_id = ? ORDER BY followup_date DESC, created_at DESC";
            
    if ($histStmt = $mysqli->prepare($sql)) {
        $histStmt->bind_param("i", $pid);
        $histStmt->execute();
        $res = $histStmt->get_result();
        $html = '<ul class="list-group list-group-flush">';
        if ($res->num_rows > 0) {
            while ($row = $res->fetch_assoc()) {
                $badgeClass = 'bg-secondary';
                if($row['followup_status'] == 'Interested') $badgeClass = 'bg-success';
                if($row['followup_status'] == 'Not Interested') $badgeClass = 'bg-danger';
                if($row['followup_status'] == 'Call Back Later') $badgeClass = 'bg-warning text-dark';
                
                $html .= '<li class="list-group-item">';
                $html .= '<div class="d-flex justify-content-between mb-1">';
                $html .= '<strong>'.date('d-M-Y', strtotime($row['followup_date'])).'</strong>';
                // Added Edit Option Button
                $html .= '<a href="javascript:void(0)" class="text-primary small text-decoration-none" onclick="editRemark('.$row['id'].')"><i class="fas fa-edit"></i> Edit</a>';
                $html .= '</div>';
                
                $html .= '<span class="badge '.$badgeClass.'">'.$row['followup_status'].'</span> <small class="text-muted">via '.$row['comm_medium'].'</small>';
                
                // Normal view of remark
                $html .= '<p class="mb-0 small mt-1" id="remark-text-'.$row['id'].'">'.nl2br(h($row['remarks'])).'</p>';
                
                // Hidden Edit Form for remark
                $html .= '<div id="edit-form-'.$row['id'].'" class="d-none mt-2">';
                $html .= '<textarea class="form-control form-control-sm mb-1" id="edit-remark-val-'.$row['id'].'" rows="2">'.h($row['remarks']).'</textarea>';
                $html .= '<button type="button" class="btn btn-sm btn-success py-0 px-2" onclick="saveRemark('.$row['id'].')">Save</button> ';
                $html .= '<button type="button" class="btn btn-sm btn-secondary py-0 px-2" onclick="cancelEdit('.$row['id'].')">Cancel</button>';
                $html .= '</div>';

                if ($row['next_followup_date']) $html .= '<div class="text-danger small fw-bold mt-1">Next: '.date('d-M-Y', strtotime($row['next_followup_date'])).'</div>';
                $html .= '</li>';
            }
        } else { $html .= '<li class="list-group-item text-center">No history.</li>'; }
        echo $html . '</ul>';
    }
    exit;
}

// ---------------------------------------------------------
// 4. HANDLE POST ACTIONS (Save New Log - Manual)
// ---------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add_remark') {
    $publisher_id = (int)$_POST['publisher_id'];
    $followup_date = $_POST['followup_date'];
    $remarks = trim($_POST['remarks']);
    $next_date = !empty($_POST['next_followup_date']) ? $_POST['next_followup_date'] : NULL;
    $medium = !empty($_POST['comm_medium']) ? $_POST['comm_medium'] : NULL;
    $status = !empty($_POST['followup_status']) ? $_POST['followup_status'] : NULL;

    if ($publisher_id > 0 && !empty($followup_date) && !empty($remarks)) {
        $stmt = $mysqli->prepare("INSERT INTO client_followup_history (client_id, followup_date, next_followup_date, comm_medium, followup_status, remarks, created_at) VALUES (?, ?, ?, ?, ?, ?, NOW())");
        $stmt->bind_param("isssss", $publisher_id, $followup_date, $next_date, $medium, $status, $remarks);
        if ($stmt->execute()) redirect_with(['msg' => 'Log added successfully', 'type' => 'success']);
    }
}

require 'header.php'; 
?>
<style>
    .table thead th {
        background-color: #6c757d !important;
        color: white !important;
        border-bottom: none !important;
    }
</style>
<?php

// ---------------------------------------------------------
// FETCH COUNTRIES FOR DROPDOWN
// ---------------------------------------------------------
$countries =[];
$res_countries = $mysqli->query("SELECT DISTINCT country FROM publisher_master WHERE country IS NOT NULL AND country != '' ORDER BY country ASC");
if ($res_countries) {
    while ($row_c = $res_countries->fetch_assoc()) {
        $countries[] = $row_c['country'];
    }
}

// ---------------------------------------------------------
// BUILD FILTER QUERY
// ---------------------------------------------------------
$q = trim($_GET['q'] ?? '');
$filter_country = trim($_GET['country'] ?? '');
$filter_status = trim($_GET['status'] ?? '');
$sort = $_GET['sort'] ?? 'last_followup';
$current_date = date('Y-m-d');

$sql = "SELECT pm.*, 
        (SELECT MAX(followup_date) FROM client_followup_history WHERE client_id = pm.id) as last_followup,
        (SELECT next_followup_date FROM client_followup_history WHERE client_id = pm.id ORDER BY followup_date DESC, created_at DESC LIMIT 1) as next_followup_date,
        (SELECT followup_status FROM client_followup_history WHERE client_id = pm.id ORDER BY followup_date DESC, created_at DESC LIMIT 1) as last_status
        FROM publisher_master pm";

$where_conds = [];

// Expanded Search Filter Logic
if ($q !== '') {
    $q_esc = $mysqli->real_escape_string($q);
    $where_conds[] = "(
        pm.publisher_name LIKE '%$q_esc%' OR 
        pm.code LIKE '%$q_esc%' OR 
        pm.email LIKE '%$q_esc%' OR 
        pm.contact_no LIKE '%$q_esc%' OR 
        pm.contact_person LIKE '%$q_esc%' OR 
        pm.link LIKE '%$q_esc%' OR 
        pm.country LIKE '%$q_esc%'
    )";
}

// Country Search
if ($filter_country !== '') {
    $c_esc = $mysqli->real_escape_string($filter_country);
    $where_conds[] = "pm.country = '$c_esc'";
}

// Status Filter
if ($filter_status !== '') {
    $status_esc = $mysqli->real_escape_string($filter_status);
    $where_conds[] = "pm.id IN (
        SELECT client_id FROM client_followup_history 
        WHERE followup_status = '$status_esc'
        AND (client_id, followup_date) IN (
            SELECT client_id, MAX(followup_date) 
            FROM client_followup_history 
            GROUP BY client_id
        )
    )";
}

if (!empty($where_conds)) {
    $sql .= " WHERE " . implode(" AND ", $where_conds);
}

// Sorting logic
if ($sort === 'last_status') {
    $sql .= " ORDER BY last_status";
} elseif ($sort === 'publisher_name') {
    $sql .= " ORDER BY publisher_name";
} else {
    $sql .= " ORDER BY last_followup DESC";
}

$publishers = $mysqli->query($sql);
?>

<div class="container-fluid py-4" style="margin-top: 60px;">
    
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h3 class="fw-bold text-dark"><i class="fas fa-bullhorn me-2"></i>Follow-up</h3>
            <p class="text-muted">Track interactions and manage schedules</p>
        </div>
        <div class="btn-group">
            <button type="button" class="btn btn-warning fw-bold" data-bs-toggle="modal" data-bs-target="#importModal">
                <i class="fas fa-file-excel me-1"></i> Import Excel
            </button>
        </div>
    </div>

    <!-- DASHBOARD STATS -->
    <div class="row mb-4">
        <div class="col-md-3"><div class="card bg-primary text-white p-3"><h5><?= $total_publishers ?></h5>Total Publishers</div></div>
        <div class="col-md-3"><div class="card bg-success text-white p-3"><h5><?= $active_publishers ?></h5>Active</div></div>
        <div class="col-md-3"><div class="card bg-info text-white p-3"><h5><?= $followup_today ?></h5>Today's Tasks</div></div>
        <div class="col-md-3"><div class="card bg-danger text-white p-3"><h5><?= $overdue_followups ?></h5>Overdue</div></div>
    </div>

    <?php if (!empty($_GET['msg'])): ?>
    <div class="alert alert-<?= h($_GET['type'] ?? 'info') ?> alert-dismissible fade show"><?= h($_GET['msg']) ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
    <?php endif; ?>

    <!-- FILTER BAR -->
    <div class="card mb-3 shadow-sm border-0">
        <div class="card-body">
            <form method="get" class="row g-2 align-items-end">
                <div class="col-md-3">
                    <input type="text" name="q" class="form-control" placeholder="Search by Name, Email, Contact, Person, Country, Link..." value="<?= h($q) ?>">
                </div>
                
                <div class="col-md-2">
                    <select name="country" class="form-select">
                        <option value="">Sort by Countries</option>
                        <?php foreach($countries as $c): ?>
                            <option value="<?= h($c) ?>" <?= $filter_country === $c ? 'selected' : '' ?>><?= h($c) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="col-md-2">
                    <select name="status" class="form-select">
                        <option value="">Sort by Status</option>
                        <option value="Interested" <?= $filter_status === 'Interested' ? 'selected' : '' ?>>Interested</option>
                        <option value="Call Back Later" <?= $filter_status === 'Call Back Later' ? 'selected' : '' ?>>Call Back Later</option>
                        <option value="Not Pickup" <?= $filter_status === 'Not Pickup' ? 'selected' : '' ?>>Not Pickup</option>
                        <option value="Voice Message" <?= $filter_status === 'Voice Message' ? 'selected' : '' ?>>Voice Message</option>
                        <option value="Not Interested" <?= $filter_status === 'Not Interested' ? 'selected' : '' ?>>Not Interested</option>
                    </select>
                </div>

                <!--div class="col-md-2">
                    <select name="sort" class="form-select">
                        <option value="last_followup" <?= $sort==='last_followup'?'selected':'' ?>>Sort by Last Action</option>
                        <option value="publisher_name" <?= $sort==='publisher_name'?'selected':'' ?>>Sort by Name</option>
                        <option value="last_status" <?= $sort==='last_status'?'selected':'' ?>>Sort by Status</option>
                    </select>
                </div-->
                
                <div class="col-md-3 d-flex gap-2">
                    <button type="submit" class="btn btn-primary flex-grow-1"><i class="fas fa-filter me-1"></i>Filter</button>
                    <a href="client_followup.php" class="btn btn-outline-secondary flex-grow-1"><i class="fas fa-times me-1"></i>Reset</a>
                </div>
            </form>
        </div>
    </div>

    <!-- TABLE -->
    <div class="card shadow border-0">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0 text-nowrap">
                <thead>
                    <tr>
                        <th>S.No</th>
                        <th>Country</th>
                        <th>Publisher Name</th>
                        <th>Contact No.</th>
                        <th>E-mail</th>
                        <th>Contact Person</th>
                        <th>Designation</th>
                        <th>Link</th>
                        <th>Next Follow-up</th>
                        <th>Last Action</th>
                        <th>Status</th>
                        <th class="text-end">Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php 
                    if ($publishers && $publishers->num_rows > 0):
                        $i=0; 
                        while ($row = $publishers->fetch_assoc()): $i=$i+1;
                    ?>
                    <tr onclick="openFollowup(<?= $row['id'] ?>, '<?= h(addslashes($row['publisher_name'])) ?>')" style="cursor:pointer">
                        <td><?= $i;?></td>
                        <td><?= h($row['country']) ?></td>
                        <td>
                            <strong><?= h($row['publisher_name']) ?></strong>
                            <?php if(!empty($row['code'])): ?>
                                <br><span class="badge bg-light text-dark"><?= h($row['code']) ?></span>
                            <?php endif; ?>
                        </td>
                        <td><i class="fas fa-phone text-success me-1"></i><?= h($row['contact_no']) ?></td>
                        <td>
                            <a href="mailto:<?= h($row['email']) ?>" onclick="event.stopPropagation();" class="text-decoration-none">
                                <i class="fas fa-envelope text-primary me-1"></i><?= h($row['email']) ?>
                            </a>
                        </td>
                        <td><?= h($row['contact_person']) ?></td>
                        <td><?= h($row['designation']) ?></td>
                        <td>
                            <?php if(!empty($row['link'])): ?>
                                <a href="<?= h($row['link']) ?>" target="_blank" onclick="event.stopPropagation();" class="text-decoration-none">
                                    <i class="fas fa-external-link-alt"></i> <?= h($row['link']) ?>
                                </a>
                            <?php else: ?>
                                -
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if($row['next_followup_date']): ?>
                                <span class="<?= $row['next_followup_date'] < $current_date ? 'text-danger fw-bold' : 'text-primary' ?>">
                                    <?= formatDate($row['next_followup_date']) ?>
                                </span>
                            <?php else: ?> - <?php endif; ?>
                        </td>
                        <td><?= formatDate($row['last_followup']) ?></td>
                        <td>
                            <?php if($row['last_status']): ?>
                                <?php
                                $statusClass = 'bg-secondary';
                                if($row['last_status'] == 'Interested') $statusClass = 'bg-success';
                                if($row['last_status'] == 'Not Interested') $statusClass = 'bg-danger';
                                if($row['last_status'] == 'Call Back Later') $statusClass = 'bg-warning text-dark';
                                if($row['last_status'] == 'Not Pickup') $statusClass = 'bg-info';
                                if($row['last_status'] == 'Voice Message') $statusClass = 'bg-secondary';
                                ?>
                                <span class="badge <?= $statusClass ?>"><?= h($row['last_status']) ?></span>
                            <?php endif; ?>
                        </td>
                        <td class="text-end">
                            <button onclick="event.stopPropagation(); openFollowup(<?= $row['id'] ?>, '<?= h(addslashes($row['publisher_name'])) ?>')" class="btn btn-sm btn-outline-primary">Log</button>
                        </td>
                    </tr>
                    <?php 
                        endwhile; 
                    else: 
                    ?>
                    <tr>
                        <td colspan="12" class="text-center py-4 text-muted">
                            <i class="fas fa-folder-open mb-2 fa-2x opacity-50"></i><br>
                            No publishers found matching the selected criteria.
                        </td>
                    </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- IMPORT MODAL -->
<div class="modal fade" id="importModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form action="" method="post" enctype="multipart/form-data">
                <input type="hidden" name="action" value="import_excel">
                <div class="modal-header bg-warning">
                    <h5 class="modal-title fw-bold">Import Follow-up Logs</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <p class="small text-muted">Use the exact <strong>Publisher Name</strong> or Code as saved in Publisher Master.</p>
                    <div class="mb-3">
                        <label class="form-label fw-bold">Select XLSX File</label>
                        <input type="file" name="excel_file" class="form-control" accept=".xlsx" required>
                    </div>
                    
                    <div class="alert alert-secondary p-2 small">
                        <strong>Excel Column Order:</strong>
                        <br>1. Publisher Name | 2. Followup Date | 3. Next Followup Date | 4. Medium | 5. Status | 6. Remarks
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-link btn-sm text-decoration-none" onclick="downloadSample()">Download Sample CSV</button>
                    <button type="submit" class="btn btn-dark">Start Import</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- ENTRY MODAL (Manual Adding) -->
<div class="modal fade" id="followupModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="modalPublisherName"></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="row">
                    <div class="col-md-5">
                        <form method="post">
                            <input type="hidden" name="action" value="add_remark">
                            <input type="hidden" name="publisher_id" id="modalPublisherId">
                            <div class="mb-2"><label class="small fw-bold">Date</label><input type="date" name="followup_date" class="form-control form-control-sm" value="<?= date('Y-m-d') ?>" required></div>
                            <div class="mb-2"><label class="small fw-bold">Next Date</label><input type="date" name="next_followup_date" class="form-control form-control-sm"></div>
                            <div class="mb-2">
                                <label class="small fw-bold">Medium</label>
                                <select name="comm_medium" class="form-select form-select-sm">
                                    <option>Call</option><option>Email</option><option>WhatsApp</option>
                                </select>
                            </div>
                            <div class="mb-2">
                                <label class="small fw-bold">Status</label>
                                <select name="followup_status" class="form-select form-select-sm">
                                    <option>Interested</option><option>Call Back Later</option><option>Not Pickup</option><option>Voice Message</option><option>Not Interested</option>
                                </select>
                            </div>
                            <div class="mb-3"><label class="small fw-bold">Remarks</label><textarea name="remarks" class="form-control form-control-sm" rows="3" required></textarea></div>
                            <button type="submit" class="btn btn-primary btn-sm w-100">Save Log</button>
                        </form>
                    </div>
                    <div class="col-md-7 border-start">
                        <h6>History</h6>
                        <div id="historyContainer" style="max-height: 400px; overflow-y: auto;"></div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
let currentPublisherId = 0;

function openFollowup(id, name) {
    currentPublisherId = id;
    document.getElementById('modalPublisherId').value = id;
    document.getElementById('modalPublisherName').textContent = name;
    refreshHistory();
    new bootstrap.Modal(document.getElementById('followupModal')).show();
}

function refreshHistory() {
    fetch(`client_followup.php?fetch_history=1&publisher_id=${currentPublisherId}`)
        .then(r => r.text()).then(html => { document.getElementById('historyContainer').innerHTML = html; });
}

// ---- EDIT REMARK JS FUNCTIONS ----
function editRemark(id) {
    document.getElementById('remark-text-' + id).classList.add('d-none');
    document.getElementById('edit-form-' + id).classList.remove('d-none');
}

function cancelEdit(id) {
    document.getElementById('remark-text-' + id).classList.remove('d-none');
    document.getElementById('edit-form-' + id).classList.add('d-none');
}

function saveRemark(id) {
    const newRemark = document.getElementById('edit-remark-val-' + id).value;
    
    const formData = new FormData();
    formData.append('action', 'edit_remark_ajax');
    formData.append('log_id', id);
    formData.append('remark', newRemark);

    fetch('client_followup.php', {
        method: 'POST',
        body: formData
    })
    .then(r => r.text())
    .then(res => {
        if(res.trim() === 'SUCCESS') {
            refreshHistory(); // Reloads history to show the updated remark
        } else {
            alert('Error updating remark. Please try again.');
        }
    })
    .catch(err => alert('Network Error'));
}

function downloadSample() {
    const content = "Publisher Name,Followup Date,Next Followup Date,Medium,Status,Remarks\n" +
                    "Oxford University Press,2026-03-01,2026-03-15,Call,Interested,Discussed new titles";
    const blob = new Blob([content], { type: 'text/csv' });
    const url = window.URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = 'followup_sample.csv';
    a.click();
}
</script>
</body>
</html>