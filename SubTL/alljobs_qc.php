<br><br><br>
<?php
session_start();
// NOTE: Ensure 'db.php' contains your database connection logic ($mysqli variable)
require 'db.php'; 

// --- Helper Functions ---

/**
 * Escapes HTML special characters for safe output.
 */
function h($s) {
    return htmlspecialchars((string)$s, ENT_QUOTES);
}

/**
 * Normalizes a process string (e.g., 'CE+pag' -> 'CE+PAG') for mapping.
 */
function normalize_process($p){
    if(!$p) return '';
    $parts = array_map('trim', explode('+', strtoupper(str_replace(' ', '', $p))));
    sort($parts);
    return implode('+', $parts);
}

/**
 * Formats a date string for display.
 */
function formatDate($date, $format = 'd-m-Y') {
    if (!$date) return '';
    $dateObj = DateTime::createFromFormat('Y-m-d', $date);
    return $dateObj ? $dateObj->format($format) : $date;
}

// --- Configuration & User Details ---

// Defines which stages are required for each process type (used for filtering)
$processStageMap =[
    'CE'          => ['CE','LE'],
    'PAG'         => ['PAG','QC'],
    'CE+PAG'      => ['CE','LE','PAG','QC'],
    'CE+PAG+XML'  => ['CE','LE','PAG','QC','XML'],
    'CE+XML'      => ['CE','LE','XML'],
    'PAG+XML'     =>['PAG','QC','XML'],
    'XML'         => ['XML'],
];

// Get user details from session
$logged_user = $_SESSION['username'] ?? '';
$role_name = $_SESSION['role_name'] ?? '';
$uid = $_SESSION['uid'] ?? 0;

// --- Status Update Handling (AJAX) ---

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_qc_status'])) {
    // Crucial for AJAX: set header and prevent display of PHP warnings/HTML
    header('Content-Type: application/json');
    
    $job_id = (int)$_POST['job_id'];
    $qc_status = trim($_POST['qc_status'] ?? '');
    $upload_by = $logged_user;
    
    if (empty($qc_status)) {
        echo json_encode(['success' => false, 'message' => 'Please select a status.']);
        exit;
    }

    // 1. Fetch necessary job and status data
    $check_sql = "SELECT 
        c.client_name, p.job_name as process_name,
        pag.status as pag_status, qc.status as qc_status
    FROM jobs j
    JOIN client_master c ON j.client_id = c.client_id
    JOIN job_master p ON j.process_id = p.job_id
    LEFT JOIN pag ON j.job_id = pag.job_id
    LEFT JOIN qc ON j.job_id = qc.job_id
    WHERE j.job_id = ?";
    
    $stmt = $mysqli->prepare($check_sql);
    if (!$stmt) {
        echo json_encode(['success' => false, 'message' => 'Prepare error: ' . $mysqli->error]);
        exit;
    }
    $stmt->bind_param("i", $job_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $job_data = $result->fetch_assoc();
    $stmt->close();
    
    if (!$job_data) {
        echo json_encode(['success' => false, 'message' => 'Job not found.']);
        exit;
    }
    
    // 2. Check if PAG and QC stages are completed (No need to check all stages)
    $process_name = $job_data['process_name'];
    $norm_proc = normalize_process($process_name);
    $required_stages = $processStageMap[$norm_proc] ??[];
    
    $can_upload = true;
    if (in_array('PAG', $required_stages) && ($job_data['pag_status'] ?? '') !== 'Completed') {
        $can_upload = false;
    }
    if (in_array('QC', $required_stages) && ($job_data['qc_status'] ?? '') !== 'Completed') {
        $can_upload = false;
    }
    
    if (!$can_upload) {
        echo json_encode(['success' => false, 'message' => 'PAG and QC stages must be completed before updating status.']);
        exit;
    }
    
    // 3. Update or Insert into job_uploads_qc
    $mysqli->begin_transaction();
    
    try {
        // Check if a record already exists for this job in job_uploads_qc
        $check_exist = $mysqli->query("SELECT id FROM job_uploads_qc WHERE job_id = $job_id");
        
        if ($check_exist->num_rows > 0) {
            // Update existing record
            $update_sql = "UPDATE job_uploads_qc SET status = ?, upload_date = NOW(), upload_by = ? WHERE job_id = ?";
            $stmt = $mysqli->prepare($update_sql);
            if (!$stmt) throw new Exception('Prepare error for update: ' . $mysqli->error);
            $stmt->bind_param("ssi", $qc_status, $upload_by, $job_id);
        } else {
            // Insert new record
            $insert_sql = "INSERT INTO job_uploads_qc (job_id, upload_date, upload_by, status) VALUES (?, NOW(), ?, ?)";
            $stmt = $mysqli->prepare($insert_sql);
            if (!$stmt) throw new Exception('Prepare error for insert: ' . $mysqli->error);
            $stmt->bind_param("iss", $job_id, $upload_by, $qc_status);
        }
        
        if (!$stmt->execute()) {
            throw new Exception('Execute error: ' . $stmt->error);
        }
        $stmt->close();
        
        $mysqli->commit();
        
        // SUCCESS: Return success message
        echo json_encode([
            'success' => true, 
            'message' => 'QC Status updated successfully!',
            'new_status' => $qc_status
        ]);
        
    } catch (Exception $e) {
        $mysqli->rollback();
        echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
    }
    
    exit; // Crucial: Stop execution after AJAX response
}

// --- Data Loading and Filtering (HTML Display) ---

// Load all processes
$all_processes =[];
$res = $mysqli->query("SELECT job_id, job_name FROM job_master WHERE status='Active' ORDER BY job_name");
if (!$res) die('Error loading processes: ' . $mysqli->error);
while ($r = $res->fetch_assoc()) $all_processes[$r['job_id']] = $r['job_name'];

// Role-based filtering for 'Executive'
$where_clauses =[];
$params =[];
$types = '';

if ($role_name === 'Executive') {
    $stmt = $mysqli->prepare("SELECT process, process1 FROM employee_master WHERE employee_username = ? AND status = 'Active' LIMIT 1");
    $stmt->bind_param("s", $logged_user);
    $stmt->execute();
    $stmt->bind_result($exec_process, $exec_process1);
    $stmt->fetch();
    $stmt->close();

    $processList = array_filter([$exec_process, $exec_process1]);
    $exec_process_combined = implode('+', array_map(function($pid) use ($all_processes) {
        return is_numeric($pid) ? ($all_processes[$pid] ?? '') : $pid;
    }, $processList));
    
    $exec_stages = explode('+', str_replace(' ', '', strtoupper($exec_process_combined)));
    
    // 1. Filter jobs by process_id that includes the executive's stages
    $exec_process_ids =[];
    foreach ($all_processes as $pid => $pname) {
        $process_stages = $processStageMap[normalize_process($pname)] ??[];
        foreach ($exec_stages as $exec_stage) {
            if (in_array($exec_stage, $process_stages, true)) {
                $exec_process_ids[] = $pid;
                break;
            }
        }
    }

    if (!empty($exec_process_ids)) {
        $process_placeholders = implode(',', array_fill(0, count($exec_process_ids), '?'));
        $where_clauses[] = "j.process_id IN ($process_placeholders)";
        $params = array_merge($params, $exec_process_ids);
        $types .= str_repeat('i', count($exec_process_ids));

        // 2. Filter jobs by assigned stages
        $assigned_fields_map =[
            'CE' => 'assigned_ce', 'LE' => 'assigned_le', 'PAG' => 'assigned_pag', 
            'QC' => 'assigned_qc', 'XML' => 'assigned_xml',
        ];

        $stage_conditions =[];
        foreach ($exec_stages as $stage) {
            if (isset($assigned_fields_map[$stage])) {
                $field = $assigned_fields_map[$stage];
                $stage_conditions[] = "($field IS NULL OR $field = ?)";
                $params[] = $logged_user;
                $types .= 's';
            }
        }
        if ($stage_conditions) {
            $where_clauses[] = '(' . implode(' OR ', $stage_conditions) . ')';
        }
    }
}

// Build WHERE clause
$where_sql = '';
if ($where_clauses) {
    $where_sql = 'WHERE ' . implode(' AND ', $where_clauses);
}

// Main query to fetch jobs targeting job_uploads_qc
$sql = "SELECT 
    j.job_id, j.job_date, c.client_name, j.journal_issue, j.article, 
    p.job_name as process_name, j.due_date,
    
    -- Statuses from related tables (Focus on PAG and QC)
    pag.status as pag_status, qc.status as qc_status,
    
    -- Status from job_uploads_qc table
    (SELECT status FROM job_uploads_qc WHERE job_id = j.job_id ORDER BY upload_date DESC LIMIT 1) as qc_final_status

FROM jobs j
JOIN client_master c ON j.client_id = c.client_id
JOIN job_master p ON j.process_id = p.job_id
LEFT JOIN pag ON j.job_id = pag.job_id
LEFT JOIN qc ON j.job_id = qc.job_id
$where_sql
ORDER BY j.job_id DESC";

// Execute query with dynamic binding if filters are applied
if ($where_sql && $types) {
    $stmt = $mysqli->prepare($sql);
    if (!$stmt) die('Error preparing jobs query: ' . $mysqli->error);
    
    $bind_params = array_merge([$types], $params);
    $refs =[];
    foreach ($bind_params as $key => $value) {
        $refs[$key] = &$bind_params[$key];
    }
    call_user_func_array([$stmt, 'bind_param'], $refs);
    
    $stmt->execute();
    $jobs_res = $stmt->get_result();
    $stmt->close();
} else {
    $jobs_res = $mysqli->query($sql);
    if (!$jobs_res) die('Error executing jobs query: ' . $mysqli->error);
}

// Fetch all data for DataTable
$jobs_data =[];
while ($job = $jobs_res->fetch_assoc()) {
    $jobs_data[] = $job;
}

require 'header.php';
?>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet" />
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet" />
    <link href="https://cdn.datatables.net/1.13.6/css/dataTables.bootstrap5.min.css" rel="stylesheet" />
    <style>
        .badge { font-size: 0.75rem; }
        .update-btn:disabled { opacity: 0.5; cursor: not-allowed; }
        .status-badge { background-color: #28a745 !important; }
        .dataTables_wrapper { padding: 0; }
        table.dataTable { margin-top: 0 !important; margin-bottom: 0 !important; }
        .btn-sm { padding: 0.25rem 0.5rem; font-size: 0.875rem; }
        
        .modal-header {
            background: linear-gradient(135deg, #4b6cb7 0%, #182848 100%);
            color: white;
        }
        .modal-header .btn-close { filter: invert(1); }
        .fw-bold { font-weight: 600 !important; }
         #jobsTable thead.table-grey th {
        background-color: #5e6b77 !important; /* Slate Grey */
        color: #ffffff !important;           /* Keep text white */
        border-bottom: none !important;
    }
    </style>
    
    <div class="container-fluid mt-3">
        <div class="card shadow-sm">
            <div class="card-header bg-light d-flex justify-content-between align-items-center">
                <h5 class="card-title mb-0"><i class="fas fa-tasks text-primary me-2"></i> QC Jobs Management</h5>
            </div>
            <div class="card-body p-0">
                <table id="jobsTable" class="table table-striped table-hover align-middle" style="width:100%">
                  <!-- <thead class="table-dark"> -->
                    <thead class="table-grey">
                    <tr>
                        <th>Job ID</th>
                        <th>Date</th>
                        <th>Client</th>
                        <th>Journal/Issue</th>
                        <th>Article</th>
                        <th>Process</th>
                        <th>Stage Status (PAG & QC)</th>
                        <th>Final QC Status</th>
                        <th>Due Date</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($jobs_data as $job): 
                        // Determine required stages for this job's process
                        $process_name = $job['process_name'];
                        $norm_proc = normalize_process($process_name);
                        $required_stages = $processStageMap[$norm_proc] ??[];
                        
                        // Check if PAG and QC are required and completed (ignoring others like CE, LE, XML)
                        $can_upload = true;
                        if (in_array('PAG', $required_stages) && ($job['pag_status'] ?? '') !== 'Completed') {
                            $can_upload = false;
                        }
                        if (in_array('QC', $required_stages) && ($job['qc_status'] ?? '') !== 'Completed') {
                            $can_upload = false;
                        }

                        $pag_required = in_array('PAG', $required_stages);
                        $qc_required = in_array('QC', $required_stages);
                        $pag_completed = (($job['pag_status'] ?? '') === 'Completed');
                        $qc_completed  = (($job['qc_status'] ?? '') === 'Completed');
                        
                        $qc_final_status = h($job['qc_final_status'] ?? 'Pending');
                    ?>
                        <tr>
                            <td><?= h($job['job_id']) ?></td>
                            <td><?= formatDate($job['job_date']) ?></td>
                            <td><?= h($job['client_name']) ?></td>
                            <td><?= h($job['journal_issue']) ?></td>
                            <td><?= h($job['article']) ?></td>
                            <td>
                                <span class="badge bg-secondary"><?= h($job['process_name']) ?></span>
                            </td>
                            <td>
                                <?php if ($pag_required): ?>
                                    <span class="badge <?= $pag_completed ? 'bg-success' : 'bg-warning text-dark' ?> text-white mb-1 d-block">
                                        <?= $pag_completed ? '✓' : '⏱️' ?> PAG: <?= h($job['pag_status'] ?? 'Pending') ?>
                                    </span>
                                <?php endif; ?>
                                
                                <?php if ($qc_required): ?>
                                    <span class="badge <?= $qc_completed ? 'bg-success' : 'bg-warning text-dark' ?> text-white mb-1 d-block">
                                        <?= $qc_completed ? '✓' : '⏱️' ?> QC: <?= h($job['qc_status'] ?? 'Pending') ?>
                                    </span>
                                <?php endif; ?>
                                
                                <?php if (!$pag_required && !$qc_required): ?>
                                    <span class="badge bg-secondary text-white mb-1 d-block">N/A</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($qc_final_status !== 'Pending'): ?>
                                    <span class="badge status-badge text-white mb-2 d-block px-2 py-1">
                                        <i class="fas fa-check-circle"></i> <?= $qc_final_status ?>
                                    </span>
                                
                                <?php else: ?>
                                <button class="btn btn-sm btn-primary update-btn w-100" 
                                        data-job-id="<?= $job['job_id'] ?>"
                                        data-current-status="<?= $qc_final_status ?>"
                                        <?= !$can_upload ? 'disabled' : '' ?>
                                        title="<?= $can_upload ? 'Update Status' : 'PAG and QC stages must be completed first' ?>">
                                    <i class="fas fa-edit"></i> Update Status
                                </button>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?= formatDate($job['due_date']) ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
                </table>
            </div>
        </div>
    </div>

   <!-- Status Update Modal -->
   <div class="modal fade" id="statusModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-clipboard-check me-2"></i>Update QC Status - Job #<span id="modalJobId"></span></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <form id="statusForm">
                    <input type="hidden" name="job_id" id="statusJobId">
                    <input type="hidden" name="update_qc_status" value="1">
                    
                    <div class="mb-3">
                        <label for="qc_status" class="form-label fw-bold">Select Status Action</label>
                        <select class="form-select border-primary shadow-sm" id="qc_status" name="qc_status" required>
                            <option value="">-- Select Status --</option>
                            <option value="Move to XML">Move to XML</option>
                            <option value="QC+Upload">QC+Upload</option>
                        </select>
                    </div>
                </form>
            </div>
            <div class="modal-footer bg-light">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-primary px-4" id="submitStatus">
                    <i class="fas fa-save me-1"></i> Save Status
                </button>
            </div>
        </div>
    </div>
</div>

    <script src="https://code.jquery.com/jquery-3.7.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.6/js/dataTables.bootstrap5.min.js"></script>
    
    <script>
    $(document).ready(function() {
        // Initialize DataTable
        const jobsTable = $('#jobsTable').DataTable({
            "pageLength": 25,
            "order": [[0, 'desc']],
            "responsive": true,
            "dom": '<"row"<"col-sm-12 col-md-6"l><"col-sm-12 col-md-6"f>>rt<"row"<"col-sm-12 col-md-6"i><"col-sm-12 col-md-6"p>>',
        });

        // Open Modal handler
        $(document).on('click', '.update-btn:not(:disabled)', function() {
            const jobId = $(this).data('job-id');
            const currentStatus = $(this).data('current-status');
            
            $('#statusJobId').val(jobId);
            $('#modalJobId').text(jobId);
            $('#statusForm')[0].reset();
            
            // Pre-select if status already exists
            if(currentStatus !== 'Pending' && currentStatus !== '') {
                $('#qc_status').val(currentStatus);
            }
            
            $('#statusModal').modal('show');
        });

        // Submit form via AJAX
        $('#submitStatus').on('click', function() {
            const statusVal = $('#qc_status').val();
            if(!statusVal) {
                alert('Please select a status action.');
                return;
            }
            
            const formData = new FormData($('#statusForm')[0]);
            const submitBtn = $(this);
            
            submitBtn.prop('disabled', true).html('<i class="fas fa-spinner fa-spin me-1"></i> Saving...');
            
            $.ajax({
                url: window.location.href,
                type: 'POST',
                data: formData,
                processData: false,
                contentType: false,
                success: function(response) {
                    let result;
                    try {
                        result = typeof response === 'object' ? response : JSON.parse(response);
                        if (result.success) {
                            $('#statusModal').modal('hide');
                            window.location.reload(); 
                        } else {
                            alert('Error: ' + result.message);
                        }
                    } catch (e) {
                        alert('Status updated successfully!');
                        window.location.reload(); 
                    }
                },
                error: function(xhr, status, error) {
                    alert('Request failed. Error: ' + error);
                },
                complete: function() {
                    submitBtn.prop('disabled', false).html('<i class="fas fa-save me-1"></i> Save Status');
                }
            });
        });
        
        // Reset form when modal is closed
        $('#statusModal').on('hidden.bs.modal', function() {
            $('#statusForm')[0].reset();
        });
    });
    </script>
</body>
</html>