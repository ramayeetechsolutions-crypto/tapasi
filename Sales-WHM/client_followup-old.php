<?php
require 'db.php'; // Ensure database connection
ini_set('display_errors', 1);
error_reporting(E_ALL);
if (session_status() === PHP_SESSION_NONE) { session_start(); }

// ---------------------------------------------------------
// 0. DASHBOARD STATISTICS
// ---------------------------------------------------------
$total_clients = $mysqli->query("SELECT COUNT(*) FROM client_master")->fetch_row()[0];
$active_clients = $mysqli->query("SELECT COUNT(*) FROM client_master WHERE status='Active'")->fetch_row()[0];
$today = date('Y-m-d');
$followup_today = $mysqli->query("SELECT COUNT(*) FROM client_master WHERE next_followup_date = '$today'")->fetch_row()[0];
$overdue_followups = $mysqli->query("SELECT COUNT(*) FROM client_master WHERE next_followup_date < '$today'")->fetch_row()[0];

// ---------------------------------------------------------
// 1. HANDLE AJAX REQUESTS (Fetch History with Status)
// ---------------------------------------------------------
if (isset($_GET['fetch_history']) && isset($_GET['client_id'])) {
    $cid = (int)$_GET['client_id'];
    
    // Fetch details including medium and status
    $sql = "SELECT followup_date, next_followup_date, comm_medium, followup_status, remarks, created_at 
            FROM client_followup_history 
            WHERE client_id = ? 
            ORDER BY followup_date DESC, created_at DESC";
            
    if ($histStmt = $mysqli->prepare($sql)) {
        $histStmt->bind_param("i", $cid);
        $histStmt->execute();
        $res = $histStmt->get_result();
        
        $html = '<ul class="list-group list-group-flush">';
        if ($res->num_rows > 0) {
            while ($row = $res->fetch_assoc()) {
                $date = date('d-M-Y', strtotime($row['followup_date']));
                $created = date('H:i', strtotime($row['created_at']));
                
                // Format Status Badge
                $statusHtml = '';
                if(!empty($row['followup_status'])) {
                    $badgeClass = 'bg-secondary';
                    if($row['followup_status'] == 'Interested') $badgeClass = 'bg-success';
                    if($row['followup_status'] == 'Not Interested') $badgeClass = 'bg-danger';
                    if($row['followup_status'] == 'Call Back Later') $badgeClass = 'bg-warning text-dark';
                    $statusHtml = '<span class="badge '.$badgeClass.' me-2">'.$row['followup_status'].'</span>';
                }

                // Format Medium Text
                $mediumHtml = '';
                if(!empty($row['comm_medium'])) {
                    $mediumHtml = '<small class="text-muted fst-italic me-2">via '.$row['comm_medium'].'</small>';
                }

                // Format Next Date
                $nextStr = '';
                if (!empty($row['next_followup_date']) && $row['next_followup_date'] != '0000-00-00') {
                    $nextDate = date('d-M-Y', strtotime($row['next_followup_date']));
                    $nextStr = '<div class="text-danger small fw-bold mt-1"><i class="fas fa-arrow-right me-1"></i>Next: '.$nextDate.'</div>';
                }

                $html .= '<li class="list-group-item">';
                $html .= '<div class="d-flex justify-content-between mb-1"><strong>'.$date.'</strong> <small class="text-muted">'.$created.'</small></div>';
                
                if($statusHtml || $mediumHtml) {
                    $html .= '<div class="mb-1">'.$statusHtml . $mediumHtml . '</div>';
                }

                $html .= '<p class="mb-0 small text-dark">'.nl2br(htmlspecialchars($row['remarks'])).'</p>';
                $html .= $nextStr;
                $html .= '</li>';
            }
        } else {
            $html .= '<li class="list-group-item text-center text-muted">No previous follow-ups found.</li>';
        }
        $html .= '</ul>';
        echo $html;
    } else {
        echo "<p class='text-danger p-3'>Error loading history: " . $mysqli->error . "</p>";
    }
    exit; // Stop script here for AJAX
}

// ---------------------------------------------------------
// 2. HANDLE POST ACTIONS (Save New Log)
// ---------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add_remark') {
    $client_id = (int)$_POST['client_id'];
    $followup_date = $_POST['followup_date'];
    $remarks = trim($_POST['remarks']);
    $next_date = !empty($_POST['next_followup_date']) ? $_POST['next_followup_date'] : NULL;
    $medium = !empty($_POST['comm_medium']) ? $_POST['comm_medium'] : NULL;
    $status = !empty($_POST['followup_status']) ? $_POST['followup_status'] : NULL;

    if ($client_id > 0 && !empty($followup_date) && !empty($remarks)) {
        
        // A. Insert into History
        $stmt = $mysqli->prepare("INSERT INTO client_followup_history (client_id, followup_date, next_followup_date, comm_medium, followup_status, remarks) VALUES (?, ?, ?, ?, ?, ?)");
        
        if ($stmt) {
            $stmt->bind_param("isssss", $client_id, $followup_date, $next_date, $medium, $status, $remarks);
            
            if ($stmt->execute()) {
                $stmt->close();
                
                // B. Update Client Master (Next Follow-up Date)
                if ($next_date) {
                    $updSql = "UPDATE client_master SET next_followup_date = ? WHERE client_id = ?";
                    if ($upd = $mysqli->prepare($updSql)) {
                        $upd->bind_param("si", $next_date, $client_id);
                        $upd->execute();
                        $upd->close();
                    } 
                }
                
                echo "<script>window.location.href='client_followup.php?msg=Log added successfully&type=success';</script>";
                exit;
            } else {
                die("Error executing insert: " . $stmt->error);
            }
        } else {
            die("Error preparing insert: " . $mysqli->error);
        }
    } else {
        die("Error: Missing required fields.");
    }
}

// ---------------------------------------------------------
// 3. MAIN PAGE VIEW LOGIC
// ---------------------------------------------------------
require 'header.php'; 

// Helpers
function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function formatDate($d) { return ($d && $d != '0000-00-00') ? date('d-m-Y', strtotime($d)) : '-'; }

// Filters
$q = trim($_GET['q'] ?? '');
$sort = $_GET['sort'] ?? 'last_followup';
$dir = $_GET['dir'] ?? 'desc';

// UPDATED QUERY:
// 1. Get client data
// 2. Get Max Date (last_followup)
// 3. Get the 'comm_medium' from that SPECIFIC last followup record (last_medium)
$sql = "SELECT 
            cm.*, 
            (SELECT MAX(followup_date) FROM client_followup_history WHERE client_id = cm.client_id) as last_followup,
            (SELECT comm_medium FROM client_followup_history WHERE client_id = cm.client_id ORDER BY followup_date DESC, created_at DESC LIMIT 1) as last_medium
        FROM client_master cm";

$cond = []; $params = []; $types = '';
if ($q !== '') {
    $cond[] = "(cm.client_name LIKE ? OR cm.contact_person LIKE ? OR cm.email LIKE ? OR cm.phone LIKE ?)";
    $like = "%$q%"; $params = array_fill(0, 4, $like); $types = "ssss";
}
if ($cond) { $sql .= " WHERE " . implode(" AND ", $cond); }

$allowed_sort = ['client_name', 'created_date', 'last_followup', 'status', 'next_followup_date'];
if (!in_array($sort, $allowed_sort)) $sort = 'last_followup';
$sql .= " ORDER BY $sort " . ($dir === 'asc' ? 'asc' : 'desc');

$clients = false; $dbError = '';
try {
    if ($types) {
        $stmt = $mysqli->prepare($sql);
        if ($stmt) { $stmt->bind_param($types, ...$params); $stmt->execute(); $clients = $stmt->get_result(); }
        else { $dbError = "Prepare failed: " . $mysqli->error; }
    } else {
        $clients = $mysqli->query($sql);
        if (!$clients) { $dbError = "Query failed: " . $mysqli->error; }
    }
} catch (Exception $e) { $dbError = "Database Error: " . $e->getMessage(); }
?>

<div class="container-fluid py-4" style="margin-top: 60px;">
    
    <!-- DASHBOARD STATS -->
    <div class="mb-4">
        <h3 class="fw-bold text-dark"><i class="fas fa-users-cog me-2"></i>Client Follow-up Management</h3>
        <p class="text-muted">Track interactions and manage next follow-up schedules</p>
    </div>

    <div class="row mb-4">
        <div class="col-md-3">
            <div class="card text-white h-100 border-0 shadow-sm" style="background-color: #6f42c1;">
                <div class="card-body">
                    <h2 class="fw-bold mb-0"><?= $total_clients ?></h2>
                    <div class="d-flex justify-content-between align-items-center"><span>Total Clients</span><i class="fas fa-users fa-2x opacity-50"></i></div>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card text-white h-100 border-0 shadow-sm" style="background-color: #e83e8c;">
                <div class="card-body">
                    <h2 class="fw-bold mb-0"><?= $active_clients ?></h2>
                    <div class="d-flex justify-content-between align-items-center"><span>Active Clients</span><i class="fas fa-user-check fa-2x opacity-50"></i></div>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card text-white h-100 border-0 shadow-sm" style="background-color: #0dcaf0;">
                <div class="card-body">
                    <h2 class="fw-bold mb-0"><?= $followup_today ?></h2>
                    <div class="d-flex justify-content-between align-items-center"><span>Follow-ups Today</span><i class="fas fa-calendar-day fa-2x opacity-50"></i></div>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card text-white h-100 border-0 shadow-sm" style="background-color: #fd7e14;">
                <div class="card-body">
                    <h2 class="fw-bold mb-0"><?= $overdue_followups ?></h2>
                    <div class="d-flex justify-content-between align-items-center"><span>Overdue / Pending</span><i class="fas fa-clock fa-2x opacity-50"></i></div>
                </div>
            </div>
        </div>
    </div>

    <?php if (!empty($_GET['msg'])): ?>
    <div class="alert alert-<?= h($_GET['type'] ?? 'info') ?> alert-dismissible fade show" role="alert">
        <?= h($_GET['msg']) ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    <?php endif; ?>

    <!-- FILTER BAR -->
    <div class="card mb-3 shadow-sm border-0">
        <div class="card-header bg-white py-3">
            <h6 class="mb-0 fw-bold"><i class="fas fa-search me-2"></i>Search & Filter Clients</h6>
        </div>
        <div class="card-body py-3">
            <form method="get" class="row g-2 align-items-center">
                <div class="col-md-5">
                    <div class="input-group">
                        <span class="input-group-text bg-light"><i class="fas fa-search"></i></span>
                        <input type="text" name="q" class="form-control" placeholder="Search Client, Contact, Email..." value="<?= h($q) ?>">
                    </div>
                </div>
                <div class="col-md-3">
                    <select name="sort" class="form-select">
                        <option value="last_followup" <?= $sort==='last_followup'?'selected':'' ?>>Sort: Last Action</option>
                        <option value="next_followup_date" <?= $sort==='next_followup_date'?'selected':'' ?>>Sort: Next Follow-up</option>
                        <option value="created_date" <?= $sort==='created_date'?'selected':'' ?>>Sort: Created Date</option>
                        <option value="client_name" <?= $sort==='client_name'?'selected':'' ?>>Sort: Name</option>
                    </select>
                </div>
                <div class="col-md-2">
                    <button type="submit" class="btn btn-primary w-100 fw-bold">Filter</button>
                </div>
                <div class="col-md-2">
                    <a href="client_followup.php" class="btn btn-outline-secondary w-100">Reset</a>
                </div>
            </form>
        </div>
    </div>

    <!-- DATA TABLE -->
    <div class="card shadow border-0">
        <div class="card-header bg-info text-white py-2">
            <div class="d-flex justify-content-between align-items-center">
                <h6 class="mb-0 fw-bold">Client List</h6>
                <span class="badge bg-white text-info"><?= $clients ? $clients->num_rows : 0 ?> Records</span>
            </div>
        </div>
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0" style="font-size: 0.9rem;">
                <thead class="table-light">
                    <tr>
                        <th>Client / Website</th>
                        <th>Contact / Desig.</th>
                        <th>Contact Info</th>
                        <th>Loc.</th>
                        <th>Status</th>
                        <th>Next Follow-up</th>
                        <th>Last Action</th>
                        <th class="text-end">Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($clients && $clients->num_rows > 0): ?>
                        <?php while ($row = $clients->fetch_assoc()): ?>
                        <tr style="cursor: pointer;" 
                            data-bs-toggle="modal" 
                            data-bs-target="#followupModal"
                            onclick="openFollowup(<?= $row['client_id'] ?>, '<?= h($row['client_name']) ?>')">
                            
                            <!-- Client -->
                            <td>
                                <div class="fw-bold text-dark"><?= h($row['client_name']) ?></div>
                                <?php if(!empty($row['website'])): ?>
                                    <a href="http://<?= h($row['website']) ?>" target="_blank" onclick="event.stopPropagation()" class="small text-primary text-decoration-none"><i class="fas fa-external-link-alt"></i> <?= h($row['website']) ?></a>
                                <?php else: ?>
                                    <small class="text-muted">ID: <?= $row['client_id'] ?></small>
                                <?php endif; ?>
                            </td>

                            <!-- Contact -->
                            <td>
                                <?= h($row['contact_person']) ?>
                                <?php if(!empty($row['designation'])): ?><br><span class="badge bg-light text-dark border"><?= h($row['designation']) ?></span><?php endif; ?>
                            </td>

                            <!-- MEDIUM COLUMN (UPDATED LOGIC) -->
                            <!-- Shows what was selected in the last popup log -->
                            <td>
                                <!-- Email Display -->
                                <?php if (!empty($row['email'])): ?>
                                    <div class="d-flex align-items-center mb-1">
                                        <i class="fas fa-envelope text-primary me-2" style="width: 16px;"></i>
                                        <a href="mailto:<?= h($row['email']) ?>" class="text-dark text-decoration-none small"><?= h($row['email']) ?></a>
                                    </div>
                                <?php endif; ?>
                            
                                <!-- Phone Display -->
                                <?php if (!empty($row['phone'])): ?>
                                    <div class="d-flex align-items-center">
                                        <i class="fas fa-phone text-success me-2" style="width: 16px;"></i>
                                        <a href="tel:<?= h($row['phone']) ?>" class="text-dark text-decoration-none small"><?= h($row['phone']) ?></a>
                                    </div>
                                <?php endif; ?>
                            
                                <!-- Fallback if both are empty -->
                                <?php if (empty($row['email']) && empty($row['phone'])): ?>
                                    <span class="text-muted small">-</span>
                                <?php endif; ?>
                            </td>

                            <!-- Location -->
                            <td><small><?= h($row['country']) ?></small></td>

                            <!-- Status -->
                            <td><span class="badge rounded-pill <?= $row['status'] === 'Active' ? 'bg-success' : 'bg-secondary' ?>"><?= h($row['status']) ?></span></td>

                            <!-- Next Follow-up -->
                            <td class="bg-light">
                                <?php if(!empty($row['next_followup_date'])): ?>
                                    <span class="text-danger fw-bold"><i class="far fa-calendar-alt"></i> <?= formatDate($row['next_followup_date']) ?></span>
                                <?php else: ?>
                                    <span class="text-muted small">-</span>
                                <?php endif; ?>
                            </td>

                            <!-- Last Action -->
                            <td>
                                <?php if($row['last_followup']): ?>
                                    <span class="text-dark"><?= formatDate($row['last_followup']) ?></span>
                                <?php else: ?>
                                    <span class="text-muted small">Never</span>
                                <?php endif; ?>
                            </td>

                            <td class="text-end">
                                <button type="button" class="btn btn-sm btn-outline-primary" onclick="event.stopPropagation(); openFollowup(<?= $row['client_id'] ?>, '<?= h($row['client_name']) ?>')"><i class="fas fa-plus"></i></button>
                            </td>
                        </tr>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <tr><td colspan="8" class="text-center py-5 text-muted">No clients found.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- MODAL POPUP -->
<div class="modal fade" id="followupModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header bg-light">
                <h5 class="modal-title">Follow Up: <span id="modalClientName" class="fw-bold text-primary"></span></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="row">
                    <!-- LEFT: ENTRY FORM -->
                    <div class="col-md-5 border-end">
                        <h6 class="border-bottom pb-2 mb-3">Add New Log</h6>
                        <form method="post" action="">
                            <input type="hidden" name="action" value="add_remark">
                            <input type="hidden" name="client_id" id="modalClientId">
                            
                            <div class="mb-3">
                                <label class="form-label small fw-bold">Interaction Date</label>
                                <input type="date" name="followup_date" class="form-control" value="<?= date('Y-m-d') ?>" required>
                            </div>

                            <div class="mb-3">
                                <label class="form-label small fw-bold text-danger">Next Follow-up Date (Revised)</label>
                                <input type="date" name="next_followup_date" class="form-control">
                                <div class="form-text small">Set to remind when to contact next.</div>
                            </div>
                            
                            <!-- Medium Dropdown -->
                            <div class="mb-3">
                                <label class="form-label small fw-bold">Medium (Contact Method)</label>
                                <select name="comm_medium" class="form-select">
                                    <option value="">-- Select Medium --</option>
                                    <option value="Call">Call</option>
                                    <option value="Email">Email</option>
                                    <option value="WhatsApp">WhatsApp</option>
                                    <option value="Meeting">Meeting</option>
                                </select>
                            </div>

                            <!-- Status Dropdown -->
                            <div class="mb-3">
                                <label class="form-label small fw-bold">Interaction Status</label>
                                <select name="followup_status" class="form-select">
                                    <option value="">-- Select Result --</option>
                                    <option value="Interested">Interested</option>
                                    <option value="Call Back Later">Call Back Later</option>
                                    <option value="Not Interested">Not Interested</option>
                                    <option value="No Answer">No Answer / Voicemail</option>
                                </select>
                            </div>

                            <!-- Description -->
                            <div class="mb-3">
                                <label class="form-label small fw-bold">Description (Remarks)</label>
                                <textarea name="remarks" class="form-control" rows="4" placeholder="Enter discussion details..." required></textarea>
                            </div>

                            <div class="d-grid">
                                <button type="submit" class="btn btn-primary">Save Log</button>
                            </div>
                        </form>
                    </div>

                    <!-- RIGHT: HISTORY LIST -->
                    <div class="col-md-7">
                        <div class="d-flex justify-content-between align-items-center mb-2">
                            <h6 class="mb-0">History</h6>
                            <button class="btn btn-sm btn-link text-decoration-none" onclick="refreshHistory()"><i class="fas fa-sync-alt"></i> Refresh</button>
                        </div>
                        <div class="bg-light border rounded p-2" style="height: 480px; overflow-y: auto;" id="historyContainer">
                            <div class="text-center py-5 text-muted">Loading history...</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
let currentClientId = 0;

// Reverted to simple open function (no need to pass email/phone anymore)
function openFollowup(id, name) {
    currentClientId = id;
    document.getElementById('modalClientId').value = id;
    document.getElementById('modalClientName').textContent = name;
    refreshHistory();
}

function refreshHistory() {
    if(currentClientId === 0) return;
    const container = document.getElementById('historyContainer');
    container.innerHTML = '<div class="text-center py-5 text-muted"><div class="spinner-border spinner-border-sm" role="status"></div> Loading...</div>';
    
    fetch(`client_followup.php?fetch_history=1&client_id=${currentClientId}`)
        .then(response => response.text())
        .then(html => { container.innerHTML = html; })
        .catch(err => { container.innerHTML = '<p class="text-danger text-center">Error.</p>'; });
}
</script>

<!-- <?php // require 'footer.php'; ?> -->
</body>
</html>