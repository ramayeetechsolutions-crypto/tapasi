<br><br><br>
<?php
// Start a session if one is not already active
if (session_status() === PHP_SESSION_NONE) {
    session_start();
    if(isset($_SESSION['uid'])) $exeid=$_SESSION['uid'];
}
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require 'db.php';

function h($s) {
    return htmlspecialchars((string)$s, ENT_QUOTES);
}

function formatDate($date, $format = 'd-m-Y') {
    if (!$date) return '';
    $dateObj = DateTime::createFromFormat('Y-m-d', $date);
    return $dateObj ? $dateObj->format($format) : $date;
}

// Get CE ID from URL
$ce_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if (!$ce_id) {
    header("Location:ce_assign_tm.php?msg=Invalid CE ID&type=danger");
    exit;
}

// Get CE details with related job and client info
$query = "SELECT ce.*, 
                 j.job_id as jobs_job_id,
                 j.journal_issue, 
                 j.article,
                 j.process_id,
                 j.assigned_to,
                 j.assigned_to_tm,
                 j.assigned_ce,
                 j.assigned_le,
                 j.assigned_pag,
                 j.assigned_qc,
                 j.assigned_xml,
                 j.due_date,
                 j.due_date_ce,
                 j.due_date_le,
                 j.due_date_pag,
                 j.due_date_qc,
                 j.due_date_xml,
                 j.notes as job_notes,
                 j.queries as job_queries,
                 j.word_count as job_word_count,
                 j.file_path,
                 j.dispatch_date,
                 j.mail_subject,
                 c.client_name,
                 c.client_id,
                 p.job_name as process_name
          FROM ce 
          LEFT JOIN jobs j ON ce.job_id = j.job_id
          LEFT JOIN client_master c ON j.client_id = c.client_id
          LEFT JOIN job_master p ON j.process_id = p.job_id
          WHERE ce.id = ?";

$stmt = $mysqli->prepare($query);
$stmt->bind_param("i", $ce_id);
$stmt->execute();
$result = $stmt->get_result();
$ce = $result->fetch_assoc();
$stmt->close();

if (!$ce) {
    header("Location: ce_assign_tm.php?msg=CE record not found&type=danger");
    exit;
}

// Get current user info for reporting manager
$current_user_id = $_SESSION['uid'] ?? 0;
$reporting_manager = '';
$current_user_name = '';
if ($current_user_id) {
    $stmt = $mysqli->prepare("SELECT employee_name, reporting_manager FROM employee_master WHERE employee_id = ?");
    if ($stmt) {
        $stmt->bind_param("i", $current_user_id);
        $stmt->execute();
        $stmt->bind_result($current_user_name, $reporting_manager);
        $stmt->fetch();
        $stmt->close();
    }
}

// Get all TMs under same reporting manager for reference
$tms = [];
if ($reporting_manager) {
    $tm_query = "SELECT employee_username, employee_name FROM employee_master 
                 WHERE reporting_manager = ? AND status = 'Active' ORDER BY employee_name";
    $stmt = $mysqli->prepare($tm_query);
    $stmt->bind_param("s", $reporting_manager);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($tm = $result->fetch_assoc()) {
        $tms[$tm['employee_username']] = $tm['employee_name'];
    }
    $stmt->close();
}

require 'header.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>CE Details - Job #<?= $ce['job_id'] ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet" />
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet" />
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        * {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, sans-serif;
        }
        
        body {
            background-color: #f8fafc;
        }
        
        .page-header {
            background: white;
            border-bottom: 1px solid #e2e8f0;
            padding: 1rem 0;
            margin-bottom: 1.5rem;
            box-shadow: 0 1px 3px rgba(0,0,0,0.05);
        }
        
        .detail-card {
            background: white;
            border-radius: 12px;
            border: 1px solid #e2e8f0;
            margin-bottom: 1.5rem;
            overflow: hidden;
        }
        
        .card-header {
            background: #f8fafc;
            padding: 1rem 1.25rem;
            border-bottom: 1px solid #e2e8f0;
        }
        
        .card-header h5 {
            margin: 0;
            font-weight: 600;
            font-size: 1rem;
            color: #1e293b;
        }
        
        .card-body {
            padding: 1.25rem;
        }
        
        .info-row {
            display: flex;
            margin-bottom: 0.75rem;
            padding: 0.5rem;
            background: #f8fafc;
            border-radius: 8px;
        }
        
        .info-label {
            width: 140px;
            font-weight: 500;
            color: #64748b;
            font-size: 0.8rem;
        }
        
        .info-value {
            flex: 1;
            color: #1e293b;
            font-weight: 500;
            font-size: 0.9rem;
        }
        
        .badge-status {
            padding: 0.35rem 0.75rem;
            border-radius: 9999px;
            font-size: 0.75rem;
            font-weight: 500;
        }
        
        .badge-pending {
            background: #fef3c7;
            color: #92400e;
        }
        
        .badge-progress {
            background: #dbeafe;
            color: #1e40af;
        }
        
        .badge-completed {
            background: #dcfce7;
            color: #166534;
        }
        
        .tm-badge {
            display: inline-block;
            padding: 0.25rem 0.5rem;
            border-radius: 4px;
            font-size: 0.75rem;
            margin-right: 0.5rem;
            margin-bottom: 0.25rem;
        }
        
        .tm-content {
            background: #dcfce7;
            color: #166534;
        }
        
        .tm-images {
            background: #fae8ff;
            color: #a21caf;
        }
        
        .section-title {
            font-size: 0.85rem;
            font-weight: 600;
            color: #1e293b;
            margin-bottom: 1rem;
            padding-bottom: 0.5rem;
            border-bottom: 2px solid #e2e8f0;
        }
        
        .action-buttons {
            position: sticky;
            bottom: 1rem;
            background: white;
            padding: 1rem;
            border-radius: 12px;
            box-shadow: 0 4px 6px rgba(0,0,0,0.1);
            border: 1px solid #e2e8f0;
            margin-top: 2rem;
        }
    </style>
</head>
<body>
    <div class="page-header">
        <div class="container-fluid px-4">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <h4 class="mb-1" style="font-weight: 700; color: #1e293b;">
                        <i class="fas fa-file-alt me-2" style="color: #667eea;"></i>
                        CE Details - Job #<?= $ce['job_id'] ?>
                    </h4>
                    <p class="mb-0 text-muted small">
                        Detailed information for CE record ID: <?= $ce_id ?>
                    </p>
                </div>
                <div>
                    <a href="ce_assign_tm.php" class="btn btn-outline-secondary btn-sm me-2">
                        <i class="fas fa-arrow-left me-1"></i> Back to Grid
                    </a>
                    <a href="ce_assign_tm.php?mode=edit&id=<?= $ce_id ?>" class="btn btn-primary btn-sm">
                        <i class="fas fa-edit me-1"></i> Edit
                    </a>
                </div>
            </div>
        </div>
    </div>

    <div class="container-fluid px-4">
        <!-- Main Content -->
        <div class="row">
            <!-- Left Column -->
            <div class="col-md-6">
                <!-- Job Information -->
                <div class="detail-card">
                    <div class="card-header">
                        <h5><i class="fas fa-briefcase me-2"></i>Job Information</h5>
                    </div>
                    <div class="card-body">
                        <div class="info-row">
                            <span class="info-label">Job ID:</span>
                            <span class="info-value"><?= $ce['job_id'] ?></span>
                        </div>
                        <div class="info-row">
                            <span class="info-label">Client:</span>
                            <span class="info-value"><?= h($ce['client_name'] ?? 'N/A') ?></span>
                        </div>
                        <div class="info-row">
                            <span class="info-label">Journal/Issue:</span>
                            <span class="info-value"><?= h($ce['issue'] ?? 'N/A') ?></span>
                        </div>
                        <div class="info-row">
                            <span class="info-label">Article:</span>
                            <span class="info-value"><?= h($ce['articles'] ?? 'N/A') ?></span>
                        </div>
                        <div class="info-row">
                            <span class="info-label">Process:</span>
                            <span class="info-value"><?= h($ce['process_name'] ?? $ce['process'] ?? 'N/A') ?></span>
                        </div>
                        <div class="info-row">
                            <span class="info-label">Mail Subject:</span>
                            <span class="info-value"><?= h($ce['mail_subject'] ?? 'N/A') ?></span>
                        </div>
                    </div>
                </div>
                
                <!-- CE Details -->
                <div class="detail-card">
                    <div class="card-header">
                        <h5><i class="fas fa-cog me-2"></i>CE Details</h5>
                    </div>
                    <div class="card-body">
                        <div class="info-row">
                            <span class="info-label">Status:</span>
                            <span class="info-value">
                                <?php
                                $status = $ce['status'] ?? 'Need to Assign';
                                $status_class = '';
                                if ($status == 'Need to Assign') $status_class = 'badge-pending';
                                elseif ($status == 'In Progress') $status_class = 'badge-progress';
                                elseif ($status == 'Completed') $status_class = 'badge-completed';
                                ?>
                                <span class="badge-status <?= $status_class ?>"><?= h($status) ?></span>
                            </span>
                        </div>
                        <div class="info-row">
                            <span class="info-label">CE Date:</span>
                            <span class="info-value"><?= formatDate($ce['ce_date'] ?? '') ?: 'Not set' ?></span>
                        </div>
                        <div class="info-row">
                            <span class="info-label">EQ:</span>
                            <span class="info-value"><?= h($ce['eq'] ?? '-') ?></span>
                        </div>
                        <div class="info-row">
                            <span class="info-label">Images:</span>
                            <span class="info-value"><?= h($ce['images'] ?? '-') ?></span>
                        </div>
                        <div class="info-row">
                            <span class="info-label">Tables:</span>
                            <span class="info-value"><?= h($ce['tables_count'] ?? '0') ?></span>
                        </div>
                        <div class="info-row">
                            <span class="info-label">Reference:</span>
                            <span class="info-value"><?= h($ce['ref'] ?? '-') ?></span>
                        </div>
                        <div class="info-row">
                            <span class="info-label">Word Count:</span>
                            <span class="info-value">
                                <?php 
                                $word_count = 0;
                                if (!empty($ce['word_count']) && (int)$ce['word_count'] > 0) {
                                    $word_count = (int)$ce['word_count'];
                                } elseif (!empty($ce['job_word_count']) && (int)$ce['job_word_count'] > 0) {
                                    $word_count = (int)$ce['job_word_count'];
                                }
                                echo number_format($word_count);
                                ?>
                            </span>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Right Column -->
            <div class="col-md-6">
                <!-- Assignment Information -->
                <div class="detail-card">
                    <div class="card-header">
                        <h5><i class="fas fa-users me-2"></i>Assignment Information</h5>
                    </div>
                    <div class="card-body">
                        <div class="info-row">
                            <span class="info-label">Content TM:</span>
                            <span class="info-value">
                                <?php if (!empty($ce['assigned_to_content'])): ?>
                                    <span class="tm-badge tm-content">
                                        <i class="fas fa-file-alt me-1"></i>
                                        <?= h($ce['assigned_to_content']) ?>
                                    </span>
                                    <small class="text-muted d-block mt-1">
                                        <?= h($tms[$ce['assigned_to_content']] ?? 'Unknown') ?>
                                    </small>
                                <?php else: ?>
                                    <span class="text-muted">Not assigned</span>
                                <?php endif; ?>
                            </span>
                        </div>
                        
                        <div class="info-row">
                            <span class="info-label">Images TM:</span>
                            <span class="info-value">
                                <?php if (!empty($ce['assigned_to_img'])): ?>
                                    <span class="tm-badge tm-images">
                                        <i class="fas fa-image me-1"></i>
                                        <?= h($ce['assigned_to_img']) ?>
                                    </span>
                                    <small class="text-muted d-block mt-1">
                                        <?= h($tms[$ce['assigned_to_img']] ?? 'Unknown') ?>
                                    </small>
                                <?php else: ?>
                                    <span class="text-muted">Not assigned</span>
                                <?php endif; ?>
                            </span>
                        </div>
                        
                        <div class="info-row">
                            <span class="info-label">Assigned CE:</span>
                            <span class="info-value"><?= h($ce['assigned_ce'] ?? 'Not assigned') ?></span>
                        </div>
                        
                        <div class="info-row">
                            <span class="info-label">Assigned LE:</span>
                            <span class="info-value"><?= h($ce['assigned_le'] ?? 'Not assigned') ?></span>
                        </div>
                        
                        <div class="info-row">
                            <span class="info-label">Assigned PAG:</span>
                            <span class="info-value"><?= h($ce['assigned_pag'] ?? 'Not assigned') ?></span>
                        </div>
                        
                        <div class="info-row">
                            <span class="info-label">Assigned QC:</span>
                            <span class="info-value"><?= h($ce['assigned_qc'] ?? 'Not assigned') ?></span>
                        </div>
                        
                        <div class="info-row">
                            <span class="info-label">Assigned XML:</span>
                            <span class="info-value"><?= h($ce['assigned_xml'] ?? 'Not assigned') ?></span>
                        </div>
                    </div>
                </div>
                
                <!-- Dates & Deadlines -->
                <div class="detail-card">
                    <div class="card-header">
                        <h5><i class="fas fa-clock me-2"></i>Dates & Deadlines</h5>
                    </div>
                    <div class="card-body">
                        <div class="info-row">
                            <span class="info-label">Created:</span>
                            <span class="info-value"><?= formatDate(substr($ce['created_at'] ?? '', 0, 10)) ?></span>
                        </div>
                        <div class="info-row">
                            <span class="info-label">Last Updated:</span>
                            <span class="info-value"><?= formatDate(substr($ce['last_updated'] ?? '', 0, 10)) ?></span>
                        </div>
                        <div class="info-row">
                            <span class="info-label">Due Date:</span>
                            <span class="info-value"><?= formatDate($ce['due_date'] ?? '') ?: 'Not set' ?></span>
                        </div>
                        <div class="info-row">
                            <span class="info-label">CE Due:</span>
                            <span class="info-value"><?= formatDate($ce['due_date_ce'] ?? '') ?: 'Not set' ?></span>
                        </div>
                        <div class="info-row">
                            <span class="info-label">LE Due:</span>
                            <span class="info-value"><?= formatDate($ce['due_date_le'] ?? '') ?: 'Not set' ?></span>
                        </div>
                        <div class="info-row">
                            <span class="info-label">PAG Due:</span>
                            <span class="info-value"><?= formatDate($ce['due_date_pag'] ?? '') ?: 'Not set' ?></span>
                        </div>
                        <div class="info-row">
                            <span class="info-label">QC Due:</span>
                            <span class="info-value"><?= formatDate($ce['due_date_qc'] ?? '') ?: 'Not set' ?></span>
                        </div>
                        <div class="info-row">
                            <span class="info-label">Dispatch Date:</span>
                            <span class="info-value"><?= formatDate($ce['dispatch_date'] ?? '') ?: 'Not set' ?></span>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Notes and Queries -->
        <div class="row mt-3">
            <div class="col-md-6">
                <div class="detail-card">
                    <div class="card-header">
                        <h5><i class="fas fa-sticky-note me-2"></i>Notes</h5>
                    </div>
                    <div class="card-body">
                        <?php if (!empty($ce['job_notes']) || !empty($ce['notes'])): ?>
                            <p class="mb-0"><?= nl2br(h($ce['job_notes'] ?? $ce['notes'] ?? '')) ?></p>
                        <?php else: ?>
                            <p class="text-muted mb-0">No notes available</p>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            
            <div class="col-md-6">
                <div class="detail-card">
                    <div class="card-header">
                        <h5><i class="fas fa-question-circle me-2"></i>Queries</h5>
                    </div>
                    <div class="card-body">
                        <?php if (!empty($ce['job_queries']) || !empty($ce['queries'])): ?>
                            <p class="mb-0"><?= nl2br(h($ce['job_queries'] ?? $ce['queries'] ?? '')) ?></p>
                        <?php else: ?>
                            <p class="text-muted mb-0">No queries</p>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- File Information -->
        <?php if (!empty($ce['file_path'])): ?>
        <div class="row mt-3">
            <div class="col-12">
                <div class="detail-card">
                    <div class="card-header">
                        <h5><i class="fas fa-file me-2"></i>File</h5>
                    </div>
                    <div class="card-body">
                        <a href="<?= h($ce['file_path']) ?>" target="_blank" class="btn btn-outline-primary btn-sm">
                            <i class="fas fa-download me-1"></i> Download File
                        </a>
                        <small class="text-muted ms-2"><?= h(basename($ce['file_path'])) ?></small>
                    </div>
                </div>
            </div>
        </div>
        <?php endif; ?>
        
        <!-- Action Buttons -->
        <div class="action-buttons">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <span class="text-muted small">
                        <i class="fas fa-info-circle me-1"></i>
                        Reporting Manager: <strong><?= h($reporting_manager) ?></strong>
                    </span>
                </div>
                <div class="d-flex gap-2">
                    <a href="ce_assign_tm.php" class="btn btn-outline-secondary">
                        <i class="fas fa-arrow-left me-1"></i> Back to Grid
                    </a>
                    <a href="ce_assign_tm.php?mode=edit&id=<?= $ce_id ?>" class="btn btn-primary">
                        <i class="fas fa-edit me-1"></i> Edit Assignment
                    </a>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>