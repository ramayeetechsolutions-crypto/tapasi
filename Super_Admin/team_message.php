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
    return date('d-m-Y H:i', strtotime($d));
}

function redirect_with($params = []) {
    $base = 'team_message.php';
    if (!empty($params)) $base .= '?' . http_build_query($params);
    header("Location: $base");
    exit;
}

// Get all teams for dropdown
$teams_result = $mysqli->query("SELECT `team_id`, `team_name`, `status`, `created_date` FROM `team_master` WHERE `status` = 'Active' ORDER BY `team_name`");
$teams = [];
while($row = $teams_result->fetch_assoc()) {
    $teams[$row['team_id']] = $row;
}

// Handle POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'send_message') {
        $team_id = trim($_POST['team_id'] ?? '');
        $message_text = trim($_POST['message_text'] ?? '');
        $message_date = $_POST['message_date'] ?? date('Y-m-d H:i:s');
        
        // Basic validation
        $errors = [];
        if (empty($team_id)) $errors[] = 'Team selection is required';
        if (empty($message_text)) $errors[] = 'Message text is required';
        if (strlen($message_text) > 500) $errors[] = 'Message must be 500 characters or less';
        
        if (!empty($errors)) {
            $_SESSION['form_data'] = [
                'team_id' => $team_id,
                'message_text' => $message_text,
                'message_date' => $message_date
            ];
            redirect_with(['msg' => implode(', ', $errors), 'type' => 'danger']);
        }
        
        // Get team name
        $team_name = 'All Company';
        if ($team_id !== 'all') {
            $team_name = $teams[$team_id]['team_name'] ?? 'Unknown Team';
        }
        
        // Insert message
        $stmt = $mysqli->prepare("INSERT INTO message_master (team_id, team_name, message_text, message_date, status) VALUES (?, ?, ?, ?, 'Active')");
        if ($stmt) {
            $stmt->bind_param("ssss", $team_id, $team_name, $message_text, $message_date);
            
            if ($stmt->execute()) {
                unset($_SESSION['form_data']);
                redirect_with(['msg' => "Message sent successfully to $team_name", 'type' => 'success']);
            } else {
                redirect_with(['msg' => 'Error sending message: ' . $stmt->error, 'type' => 'danger']);
            }
            $stmt->close();
        } else {
            redirect_with(['msg' => 'Database preparation error', 'type' => 'danger']);
        }
    }
    
    if ($action === 'delete_message') {
        $message_id = (int)($_POST['message_id'] ?? 0);
        
        if ($message_id > 0) {
            // Get message details for confirmation
            $res = $mysqli->prepare("SELECT team_name, message_text FROM message_master WHERE message_id = ?");
            if ($res) {
                $res->bind_param("i", $message_id);
                $res->execute();
                $res->bind_result($team_name, $message_text);
                $res->fetch();
                $res->close();
                
                // Delete message
                $del = $mysqli->prepare("DELETE FROM message_master WHERE message_id = ?");
                if ($del) {
                    $del->bind_param("i", $message_id);
                    $del->execute();
                    $del->close();
                    
                    redirect_with(['msg' => "Message to $team_name deleted successfully", 'type' => 'warning']);
                }
            }
        }
    }
    
    if ($action === 'toggle_status') {
        $message_id = (int)($_POST['message_id'] ?? 0);
        
        if ($message_id > 0) {
            $res = $mysqli->prepare("SELECT status, team_name FROM message_master WHERE message_id = ?");
            if ($res) {
                $res->bind_param("i", $message_id);
                $res->execute();
                $res->bind_result($current_status, $team_name);
                $res->fetch();
                $res->close();
                
                if ($team_name) {
                    $newStatus = ($current_status === 'Active') ? 'Archived' : 'Active';
                    
                    $upd = $mysqli->prepare("UPDATE message_master SET status = ? WHERE message_id = ?");
                    if ($upd) {
                        $upd->bind_param("si", $newStatus, $message_id);
                        $upd->execute();
                        $upd->close();
                        
                        redirect_with(['msg' => "Message status for $team_name changed to $newStatus", 'type' => 'info']);
                    }
                }
            }
        }
    }
    
    redirect_with();
}

// Get search and filter parameters
$q = trim($_GET['q'] ?? '');
$fteam = $_GET['team'] ?? '';
$fstatus = $_GET['status'] ?? '';
$fdate = $_GET['date'] ?? '';
$sort = $_GET['sort'] ?? 'message_date';
$dir = strtolower($_GET['dir'] ?? 'desc');

// Build query
$sql = "SELECT message_id, team_id, team_name, message_text, message_date, status, created_at FROM message_master";
$cond = [];
$params = [];
$types = '';

if ($q !== '') {
    $cond[] = "(team_name LIKE ? OR message_text LIKE ?)";
    $like = "%$q%";
    $params[] = $like;
    $params[] = $like;
    $types .= 'ss';
}

if ($fteam !== '') {
    if ($fteam === 'all') {
        $cond[] = "team_id = 'all'";
    } else {
        $cond[] = "team_id = ?";
        $params[] = $fteam;
        $types .= 's';
    }
}

if ($fstatus === 'Active' || $fstatus === 'Archived') {
    $cond[] = "status = ?";
    $params[] = $fstatus;
    $types .= 's';
}

if ($fdate !== '') {
    $cond[] = "DATE(message_date) = ?";
    $params[] = $fdate;
    $types .= 's';
}

if (!empty($cond)) {
    $sql .= " WHERE " . implode(" AND ", $cond);
}

$sql .= " ORDER BY $sort $dir";

// Execute query
if (!empty($types)) {
    $stmt = $mysqli->prepare($sql);
    if ($stmt) {
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $messages = $stmt->get_result();
    } else {
        $messages = false;
    }
} else {
    $messages = $mysqli->query($sql);
}

// If query fails, show empty result
if (!$messages) {
    $messages = false;
}

// Get form data from session if exists
$formData = $_SESSION['form_data'] ?? [];
unset($_SESSION['form_data']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Company Messaging System</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body {
            background-color: #f8f9fa;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }
        .main-content {
            padding: 20px;
            max-width: 1600px;
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
        .message-cell {
            max-width: 300px;
            word-wrap: break-word;
        }
        .status-badge {
            font-size: 0.75em;
        }
        .team-badge {
            font-size: 0.8em;
        }
        .char-count {
            font-size: 0.875em;
        }
    </style>
</head>
<body>
    <?php if (file_exists('header.php')) require 'header.php'; ?>
    
    <div class="main-content">
        <?php if (!empty($_GET['msg'])): ?>
        <div class="alert alert-<?= h($_GET['type'] ?? 'info') ?> alert-dismissible fade show" role="alert">
            <?= h($_GET['msg']) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <?php endif; ?>

        <!-- Header -->
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h4><i class="fas fa-comments me-2"></i>Company Messaging System</h4>
            <button class="btn btn-success" data-bs-toggle="modal" data-bs-target="#messageModal">
                <i class="fas fa-plus me-1"></i> New Message
            </button>
        </div>

        <!-- Search and Filter Form -->
        <div class="card mb-4">
            <div class="card-body">
                <form method="get" class="row g-3 align-items-center">
                    <div class="col-md-4">
                        <input type="text" name="q" class="form-control" 
                               placeholder="Search messages..." value="<?= h($q) ?>">
                    </div>
                    <div class="col-md-2">
                        <select name="team" class="form-select">
                            <option value="">All Teams</option>
                            <option value="all" <?= $fteam === 'all' ? 'selected' : '' ?>>All Company</option>
                            <?php foreach($teams as $team_id => $team): ?>
                                <option value="<?= h($team_id) ?>" <?= $fteam === $team_id ? 'selected' : '' ?>>
                                    <?= h($team['team_name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <input type="date" name="date" class="form-control" value="<?= h($fdate) ?>">
                    </div>
                    <div class="col-md-2">
                        <select name="status" class="form-select">
                            <option value="">All Status</option>
                            <option value="Active" <?= $fstatus === 'Active' ? 'selected' : '' ?>>Active</option>
                            <option value="Archived" <?= $fstatus === 'Archived' ? 'selected' : '' ?>>Archived</option>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <select name="sort" class="form-select">
                            <option value="message_date" <?= $sort === 'message_date' ? 'selected' : '' ?>>Sort by Date</option>
                            <option value="team_name" <?= $sort === 'team_name' ? 'selected' : '' ?>>Sort by Team</option>
                        </select>
                    </div>
                    <div class="col-md-12 mt-2">
                        <button type="submit" class="btn btn-primary me-2">
                            <i class="fas fa-search me-1"></i> Search
                        </button>
                        <a href="team_message.php" class="btn btn-secondary">
                            <i class="fas fa-times me-1"></i> Clear
                        </a>
                    </div>
                </form>
            </div>
        </div>

        <!-- Messages Table -->
        <div class="card">
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th width="60">S.No</th>
                                <th width="150">Team</th>
                                <th>Message</th>
                                <th width="150">Date & Time</th>
                                <th width="100">Status</th>
                                <th width="150" class="text-center">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if($messages && $messages->num_rows > 0): ?>
                                <?php while($row = $messages->fetch_assoc()): ?>
                                <tr>
                                    <td class="fw-bold"><?= (int)$row['message_id'] ?></td>
                                    <td>
                                        <span class="badge <?= getTeamBadgeClass($row['team_name']) ?> team-badge">
                                            <?= h($row['team_name']) ?>
                                        </span>
                                    </td>
                                    <td class="message-cell">
                                        <div class="fw-semibold"><?= h(truncateMessage($row['message_text'])) ?></div>
                                    </td>
                                    <td>
                                        <small class="text-muted"><?= h(formatDate($row['message_date'])) ?></small>
                                    </td>
                                    <td>
                                        <form method="post" style="display:inline;">
                                            <input type="hidden" name="action" value="toggle_status">
                                            <input type="hidden" name="message_id" value="<?= (int)$row['message_id'] ?>">
                                            <button type="submit" class="btn btn-sm <?= $row['status'] === 'Active' ? 'btn-success' : 'btn-secondary' ?> status-badge">
                                                <?= h($row['status']) ?>
                                            </button>
                                        </form>
                                    </td>
                                    <td class="text-center">
                                        <div class="btn-group btn-group-sm" role="group">
                                            <button class="btn btn-info" onclick="viewMessage(<?= (int)$row['message_id'] ?>)" 
                                                    data-bs-toggle="modal" data-bs-target="#viewMessageModal" title="View Message">
                                                <i class="fas fa-eye"></i>
                                            </button>
                                            <form method="post" style="display:inline;" 
                                                  onsubmit="return confirm('Are you sure you want to delete this message?')">
                                                <input type="hidden" name="action" value="delete_message">
                                                <input type="hidden" name="message_id" value="<?= (int)$row['message_id'] ?>">
                                                <button type="submit" class="btn btn-danger" title="Delete Message">
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
                                            <i class="fas fa-comments fa-3x mb-3"></i>
                                            <h5>No messages found</h5>
                                            <p class="mb-0">Get started by sending your first message.</p>
                                            <button class="btn btn-primary mt-2" data-bs-toggle="modal" data-bs-target="#messageModal">
                                                <i class="fas fa-plus me-1"></i> New Message
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- Summary Card -->
        <?php if($messages && $messages->num_rows > 0): ?>
        <?php
            // Reset pointer and calculate totals
            $messages->data_seek(0);
            $total_messages = 0;
            $active_messages = 0;
            $archived_messages = 0;
            $team_counts = [];
            
            while($row = $messages->fetch_assoc()) {
                $total_messages++;
                if ($row['status'] === 'Active') $active_messages++;
                if ($row['status'] === 'Archived') $archived_messages++;
                $team_counts[$row['team_name']] = ($team_counts[$row['team_name']] ?? 0) + 1;
            }
            $unique_teams = count($team_counts);
        ?>
        <div class="card mt-4">
            <div class="card-body">
                <div class="row text-center">
                    <div class="col-md-3">
                        <h5 class="text-primary"><?= $total_messages ?></h5>
                        <small class="text-muted">Total Messages</small>
                    </div>
                    <div class="col-md-3">
                        <h5 class="text-success"><?= $active_messages ?></h5>
                        <small class="text-muted">Active</small>
                    </div>
                    <div class="col-md-3">
                        <h5 class="text-secondary"><?= $archived_messages ?></h5>
                        <small class="text-muted">Archived</small>
                    </div>
                    <div class="col-md-3">
                        <h5 class="text-info"><?= $unique_teams ?></h5>
                        <small class="text-muted">Teams</small>
                    </div>
                </div>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <!-- Message Modal -->
    <div class="modal fade" id="messageModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-paper-plane me-2"></i>Send New Message</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="post" id="messageForm">
                    <div class="modal-body">
                        <input type="hidden" name="action" value="send_message">
                        <div class="mb-3">
                            <label for="teamSelect" class="form-label">Select Team <span class="text-danger">*</span></label>
                            <select class="form-select" id="teamSelect" name="team_id" required>
                                <option value="">Choose Team...</option>
                                <option value="all" <?= ($formData['team_id'] ?? '') === 'all' ? 'selected' : '' ?>>All Company</option>
                                <?php foreach($teams as $team_id => $team): ?>
                                    <option value="<?= h($team_id) ?>" 
                                        <?= ($formData['team_id'] ?? '') === $team_id ? 'selected' : '' ?>>
                                        <?= h($team['team_name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label for="messageText" class="form-label">Message <span class="text-danger">*</span></label>
                            <textarea class="form-control" id="messageText" name="message_text" rows="5" 
                                      placeholder="Enter your message here..." required maxlength="500"><?= h($formData['message_text'] ?? '') ?></textarea>
                            <div class="form-text char-count">
                                <span id="charCount">0</span>/500 characters
                            </div>
                        </div>
                        <div class="mb-3">
                            <label for="messageDate" class="form-label">Schedule Date & Time</label>
                            <input type="datetime-local" class="form-control" id="messageDate" name="message_date" 
                                   value="<?= h($formData['message_date'] ?? date('Y-m-d\TH:i')) ?>">
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-paper-plane me-1"></i> Send Message
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- View Message Modal -->
    <div class="modal fade" id="viewMessageModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-eye me-2"></i>Message Details</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body" id="viewMessageContent">
                    <!-- Content will be loaded via JavaScript -->
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                </div>
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

            // Message character count
            const messageText = document.getElementById('messageText');
            const charCount = document.getElementById('charCount');
            
            if (messageText && charCount) {
                charCount.textContent = messageText.value.length;
                messageText.addEventListener('input', function() {
                    charCount.textContent = this.value.length;
                });
            }
        });

        function viewMessage(messageId) {
            // This would typically make an AJAX call to get message details
            // For now, we'll redirect to a view page or show in modal
            console.log('View message:', messageId);
        }

        function getTeamBadgeClass(teamName) {
            const teamClasses = {
                'All Company': 'bg-primary',
                'Sales Team': 'bg-success',
                'Marketing Team': 'bg-info',
                'Development Team': 'bg-warning',
                'HR Team': 'bg-danger',
                'Support Team': 'bg-secondary',
                'Finance Team': 'bg-dark'
            };
            return teamClasses[teamName] || 'bg-primary';
        }

        function truncateMessage(message, length = 80) {
            return message.length > length ? message.substring(0, length) + '...' : message;
        }
    </script>
</body>
</html>

<?php
// PHP helper functions
function getTeamBadgeClass($teamName) {
    $teamClasses = [
        'All Company' => 'bg-primary',
        'Sales Team' => 'bg-success',
        'Marketing Team' => 'bg-info',
        'Development Team' => 'bg-warning',
        'HR Team' => 'bg-danger',
        'Support Team' => 'bg-secondary',
        'Finance Team' => 'bg-dark'
    ];
    return $teamClasses[$teamName] ?? 'bg-primary';
}

function truncateMessage($message, $length = 80) {
    return strlen($message) > $length ? substr($message, 0, $length) . '...' : $message;
}
?>