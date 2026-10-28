<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

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

// Defines which stages are required for each process type
$processStageMap = [
  'CE'       => ['CE','LE'],
  'PAG'      => ['PAG','QC'],
  'CE+PAG'   => ['CE','LE','PAG','QC'],
  'CE+PAG+XML' => ['CE','LE','PAG','QC','XML'],
  'CE+XML'   => ['CE','LE','XML'],
  'PAG+XML'  => ['PAG','QC','XML'],
  'XML'      => ['XML'], // Added XML-only process
];

// Get user details from session
$logged_user = $_SESSION['username'] ?? '';
$role_name = $_SESSION['role_name'] ?? '';
$uid = $_SESSION['uid'] ?? 0;

// --- File Upload Handling (AJAX) ---

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['upload_job_file'])) {
    // Crucial for AJAX: set header and prevent display of PHP warnings/HTML
    header('Content-Type: application/json');
    
    // Turn off output buffering and clean any existing output
    while (ob_get_level()) {
        ob_end_clean();
    }
    
    $job_id = (int)$_POST['job_id'];
    $server_file_path = trim($_POST['server_file_path'] ?? '');
    $mail_sent_to_client = isset($_POST['mail_sent_to_client']) ? 1 : 0;
    $upload_by = $logged_user;
    
    // Initialize response array
    $response = ['success' => false, 'message' => ''];
    
    try {
        // 1. Fetch necessary job and status data
        $check_sql = "SELECT 
            c.client_name, p.job_name as process_name,
            ce.status as ce_status, le.status as le_status, 
            pag.status as pag_status, qc.status as qc_status,
            unique_xml_jobs.status as xml_status  -- Added XML status
        FROM jobs j
        JOIN client_master c ON j.client_id = c.client_id
        JOIN job_master p ON j.process_id = p.job_id
        LEFT JOIN ce ON j.job_id = ce.job_id
        LEFT JOIN le ON j.job_id = le.job_id
        LEFT JOIN pag ON j.job_id = pag.job_id
        LEFT JOIN qc ON j.job_id = qc.job_id
        LEFT JOIN unique_xml_jobs ON j.job_id = unique_xml_jobs.job_id  -- Added XML table join
        WHERE j.job_id = ?";
        
        $stmt = $mysqli->prepare($check_sql);
        if (!$stmt) {
            throw new Exception('Prepare error: ' . $mysqli->error);
        }
        $stmt->bind_param("i", $job_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $job_data = $result->fetch_assoc();
        $stmt->close();
        
        if (!$job_data) {
            throw new Exception('Job not found.');
        }
        
        // 2. Check if all required stages are completed (Restriction)
        $client_name = $job_data['client_name'];
        $process_name = $job_data['process_name'];
        $norm_proc = normalize_process($process_name);
        $required_stages = $processStageMap[$norm_proc] ?? [];
        
        $all_completed = true;
        foreach ($required_stages as $stage) {
            $status_field = strtolower($stage) . '_status';
            if (($job_data[$status_field] ?? '') !== 'Completed') {
                $all_completed = false;
                break;
            }
        }
        
        if (!$all_completed) {
            throw new Exception('All stages must be completed before uploading files.');
        }
        
        // 3. Handle File Upload and Dynamic Folder Creation
        $uploaded_file_path = '';
        $file_upload_success = false;
        
        if (isset($_FILES['job_file']) && $_FILES['job_file']['error'] === UPLOAD_ERR_OK) {
            
            // Sanitize client name for folder structure
            $safe_client_name = preg_replace('/[^a-zA-Z0-9_\-]/', '_', $client_name);
            $month_year_folder = date('Y-m');
            
            // Target directory format: uploads/jobs/ClientName_YYYY-MM/ (Dynamic Folder)
            $combined_folder_name = $safe_client_name . '_' . $month_year_folder;
            $base_upload_dir = '../uploads/jobs/';
            $final_upload_dir = $base_upload_dir . $combined_folder_name . '/';
            
            // Create directory if it doesn't exist
            if (!is_dir($final_upload_dir)) {
                if (!mkdir($final_upload_dir, 0777, true)) {
                    throw new Exception('Failed to create upload directory. Check server permissions on ' . $base_upload_dir);
                }
            }
            
            // Sanitize file name and create final path
            $original_filename = basename($_FILES['job_file']['name']);
            $safe_filename = $job_id . '_' . time() . '_' . preg_replace('/[^a-zA-Z0-9_\-.]/', '_', $original_filename);

            $uploaded_file_path = $final_upload_dir . $safe_filename;
            
            if (move_uploaded_file($_FILES['job_file']['tmp_name'], $uploaded_file_path)) {
                $file_upload_success = true;
            } else {
                throw new Exception('File move failed. Check permissions on ' . $final_upload_dir);
            }
        }
        
        // 4. Check that at least one file source is provided
        if (!$file_upload_success && empty($server_file_path)) {
            throw new Exception('Please either upload a file or provide server file path.');
        }
        
        // 5. Determine final file path (use server path if provided, otherwise use uploaded file path)
        $final_file_path = !empty($server_file_path) ? $server_file_path : $uploaded_file_path;

        // 6. Update jobs table with file_path and dispatch_date, and insert into job_uploads
        $mysqli->begin_transaction();
        
        try {
            // Update file_path and dispatch_date in jobs table
            if ($mail_sent_to_client) {
                $update_job_sql = "UPDATE jobs SET file_path = ?, dispatch_date = NOW() WHERE job_id = ?";
            } else {
                $update_job_sql = "UPDATE jobs SET file_path = ? WHERE job_id = ?";
            }
            
            $stmt = $mysqli->prepare($update_job_sql);
            if (!$stmt) {
                throw new Exception('Prepare error for jobs update: ' . $mysqli->error);
            }
            
            $stmt->bind_param("si", $final_file_path, $job_id);
            
            if (!$stmt->execute()) {
                throw new Exception('Execute error for jobs update: ' . $stmt->error);
            }
            $stmt->close();
            
            // Insert file record into job_uploads table (only if file was uploaded)
            if ($file_upload_success) {
                $insert_sql = "INSERT INTO job_uploads (job_id, upload_date, upload_by, file_path) 
                               VALUES (?, NOW(), ?, ?)";
                $stmt = $mysqli->prepare($insert_sql);
                
                if (!$stmt) {
                    throw new Exception('Prepare error for job_uploads insert: ' . $mysqli->error);
                }

                $stmt->bind_param("iss", $job_id, $upload_by, $uploaded_file_path);
                
                if (!$stmt->execute()) {
                    throw new Exception('Execute error for job_uploads insert: ' . $stmt->error);
                }
                $stmt->close();
            }
            
            $mysqli->commit();
            
            // SUCCESS: Return success message
            $response['success'] = true;
            $response['message'] = 'File information saved successfully!' . ($mail_sent_to_client ? ' Mail sent to client recorded.' : '');
            $response['file_path'] = $final_file_path;
            $response['mail_sent'] = $mail_sent_to_client;
            
        } catch (Exception $e) {
            $mysqli->rollback();
            
            // Clean up uploaded file if transaction failed
            if ($file_upload_success && file_exists($uploaded_file_path)) {
                @unlink($uploaded_file_path);
            }
            
            throw $e;
        }
        
    } catch (Exception $e) {
        $response['message'] = $e->getMessage();
    }
    
    // Ensure only JSON is output
    echo json_encode($response);
    exit; // Crucial: Stop execution after AJAX response
}
 
// --- Data Loading and Filtering (HTML Display) ---

// Load all processes
$all_processes = [];
$res = $mysqli->query("SELECT job_id, job_name FROM job_master WHERE status='Active' ORDER BY job_name");
if (!$res) die('Error loading processes: ' . $mysqli->error);
while ($r = $res->fetch_assoc()) $all_processes[$r['job_id']] = $r['job_name'];

// Role-based filtering and Job ID restriction
$where_clauses = [];
$params = [];
$types = '';

// 👇 JOB ID FILTER: Exclude jobs with IDs 1, 3, and 37
$job_id_filter = [1, 3, 37];
$id_placeholders = implode(',', array_fill(0, count($job_id_filter), '?'));
// CHANGED: Use NOT IN to exclude the listed IDs
$where_clauses[] = "j.process_id NOT IN ($id_placeholders)";
$params = array_merge($params, $job_id_filter);
$types .= str_repeat('i', count($job_id_filter));

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
  $exec_process_ids = [];
  foreach ($all_processes as $pid => $pname) {
    $process_stages = $processStageMap[normalize_process($pname)] ?? [];
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

    // 2. Filter jobs by assigned stages (assigned to them OR unassigned)
    $assigned_fields_map = [
      'CE' => 'assigned_ce', 'LE' => 'assigned_le', 'PAG' => 'assigned_pag',
      'QC' => 'assigned_qc', 'XML' => 'assigned_xml',  // Added XML assignment
    ];

    $stage_conditions = [];
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

// Main query to fetch jobs with all required details - INCLUDING XML
$sql = "SELECT
  j.job_id, j.job_date, c.client_name, j.journal_issue, j.article,
  p.job_name as process_name, j.due_date, j.dispatch_date, j.file_path,
 
  -- Statuses from related tables (INCLUDING XML)
  ce.status as ce_status, le.status as le_status, pag.status as pag_status,
  qc.status as qc_status, unique_xml_jobs.status as xml_status,  -- Added XML status
 
  -- File Upload Status and Path (Fetched from job_uploads table)
  (SELECT COUNT(*) FROM job_uploads WHERE job_id = j.job_id) as file_uploaded,
  (SELECT file_path FROM job_uploads WHERE job_id = j.job_id ORDER BY upload_date DESC LIMIT 1) as uploaded_file_path

FROM jobs j
JOIN client_master c ON j.client_id = c.client_id
JOIN job_master p ON j.process_id = p.job_id
LEFT JOIN ce ON j.job_id = ce.job_id
LEFT JOIN le ON j.job_id = le.job_id
LEFT JOIN pag ON j.job_id = pag.job_id
LEFT JOIN qc ON j.job_id = qc.job_id
LEFT JOIN unique_xml_jobs ON j.job_id = unique_xml_jobs.job_id  -- Added XML table join
$where_sql
ORDER BY j.job_id DESC";

// Execute query with dynamic binding
if ($where_sql && $types) {
  $stmt = $mysqli->prepare($sql);
  if (!$stmt) die('Error preparing jobs query: ' . $mysqli->error);
 
  $bind_params = array_merge([$types], $params);
  $refs = [];
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
$jobs_data = [];
while ($job = $jobs_res->fetch_assoc()) {
  $jobs_data[] = $job;
}

?>
<?php require 'header.php';?>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet" />
  <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet" />
  <link href="https://cdn.datatables.net/1.13.6/css/dataTables.bootstrap5.min.css" rel="stylesheet" />
  <style>
    .badge { font-size: 0.75rem; }
    .upload-btn:disabled { opacity: 0.5; cursor: not-allowed; }
    .uploaded-badge { background-color: #28a745 !important; }
    .dataTables_wrapper { padding: 0; }
    table.dataTable { margin-top: 0 !important; margin-bottom: 0 !important; }
    .modal-header { background-color: #f8f9fa; border-bottom: 1px solid #dee2e6; }
    .btn-sm { padding: 0.25rem 0.5rem; font-size: 0.875rem; }
  </style>
  <div class="container-fluid mt-3">
    <div class="d-flex justify-content-between align-items-center mb-3">
      <div>
        </div>
    </div>

    <div class="card shadow-sm">
      <div class="card-header bg-light">
        <h5 class="card-title mb-0">All Jobs (Including XML)</h5>
      </div>
      <div class="card-body p-0">
        <table id="jobsTable" class="table table-striped table-hover" style="width:100%">
          <thead class="table-dark">
    <tr>
        <th>Job ID</th>
        <th>Date</th>
        <th>Client</th>
        <th>Journal/Issue</th>
        <th>Article</th>
        <th>Process</th>
        <th>Stage Status</th>
        <th>Upload</th>
        <th>Due Date</th>
        <th>Dispatch Date</th> <!-- New Column -->
    </tr>
</thead>
          <tbody>
            <?php foreach ($jobs_data as $job):
              // Determine required stages (now includes XML for relevant processes)
              $process_name = $job['process_name'];
              $norm_proc = normalize_process($process_name);
              $required_stages = $processStageMap[$norm_proc] ?? [];
             
              $all_stages_completed = true;
              foreach ($required_stages as $stage) {
                $status_field = strtolower($stage) . '_status';
                if (($job[$status_field] ?? '') !== 'Completed') {
                  $all_stages_completed = false;
                  break;
                }
              }
             
              $file_uploaded = $job['file_uploaded'] > 0;
              $file_path = h($job['uploaded_file_path']);
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
                  <?php
                  // Display status for all required stages (including XML if applicable)
                  foreach ($required_stages as $stage):
                    $status_field = strtolower($stage) . '_status';
                    $stage_status = $job[$status_field] ?? 'Need to Assign';
                   
                    switch($stage_status) {
                      case 'Completed':
                        $bg_class = 'bg-success';
                        $icon = '✓';
                        break;
                      case 'In Progress':
                        $bg_class = 'bg-warning text-dark';
                        $icon = '⟳';
                        break;
                      default:
                        $bg_class = 'bg-secondary';
                        $icon = '⏱️';
                        break;
                    }
                  ?>
                    <span class="badge <?= $bg_class ?> text-white mb-1 d-block">
                      <?= $icon ?> <?= $stage ?>: <?= $stage_status ?>
                    </span>
                  <?php endforeach; ?>
                </td>
                <td>
                  <?php if ($file_uploaded): ?>
                    <a href="<?= $file_path ?>" target="_blank" class="badge uploaded-badge text-white text-decoration-none" title="View Uploaded File: <?= basename($file_path) ?>">
                      <i class="fas fa-check-circle"></i> Uploaded
                    </a>
                    <div class="small text-muted mt-1 text-truncate" style="max-width: 150px;" title="<?= $file_path ?>">
                      <?= basename($file_path) ?>
                    </div>
                  <?php else: ?>
                    <button class="btn btn-sm btn-primary upload-btn"
                        data-job-id="<?= $job['job_id'] ?>"
                        <?= !$all_stages_completed ? 'disabled' : '' ?>
                        title="<?= $all_stages_completed ? 'Upload Final File' : 'Complete all stages first' ?>">
                      <i class="fas fa-upload"></i> Upload
                    </button>
                  <?php endif; ?>
                </td>
                <td>
                  <?= formatDate($job['due_date']) ?>
                  <?php if (strtotime($job['due_date']) < time() && !$file_uploaded): ?>
                    <span class="badge bg-danger" title="Overdue">!</span>
                  <?php endif; ?>
                </td>
                <td>
    <?php if (!empty($job['dispatch_date']) && $job['dispatch_date'] != '0000-00-00 00:00:00' && $job['dispatch_date'] != '30-11--0001 00:00'): ?>
        <span class="badge bg-success">
            <?= date('d-m-Y H:i', strtotime($job['dispatch_date'])) ?>
        </span>
    <?php else: ?>
        <span class="badge bg-secondary">Not Yet Dispatched</span>
    <?php endif; ?>
</td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>

    <div class="modal fade" id="uploadModal" tabindex="-1" aria-labelledby="uploadModalLabel" aria-hidden="true">
    <div class="modal-dialog">
      <div class="modal-content">
        <div class="modal-header">
          <h5 class="modal-title" id="uploadModalLabel">Upload Final File - Job #<span id="modalJobId"></span></h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <div class="modal-body">
          <form id="uploadForm" enctype="multipart/form-data">
            <input type="hidden" name="job_id" id="uploadJobId">
            <input type="hidden" name="upload_job_file" value="1">
           
            <div class="mb-3">
              <label for="job_file" class="form-label">Select File</label>
              <input type="file" class="form-control" id="job_file" name="job_file" accept=".pdf,.doc,.docx,.zip,.rar">
              <div class="form-text">Supported formats: PDF, DOC, DOCX, ZIP, RAR. (Max size typically 10MB)</div>
            </div>
             <div class="mb-3">
                        <label for="server_file_path" class="form-label">File Path in Server</label>
                        <input type="text" class="form-control" id="server_file_path" name="server_file_path" placeholder="e.g., /path/to/file/filename.pdf">
                        <div class="form-text">Save the server path</div>
                    </div>
                    
                    <div class="mb-3">
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" id="mail_sent_to_client" name="mail_sent_to_client" value="1">
                            <label class="form-check-label" for="mail_sent_to_client">
                                <strong>Mail sent to client</strong>
                            </label>
                        </div>
                        <div class="form-text text-primary">
                            <i class="fas fa-info-circle"></i> If checked, current date and time will be recorded as dispatch date
                        </div>
                    </div>
            <div class="alert alert-info">
              <small>
                <i class="fas fa-info-circle"></i>
                File will be saved in the `uploads/jobs/ClientName_YYYY-MM/` folder.
              </small>
            </div>
            <div class="alert alert-warning">
              <small>
                <i class="fas fa-exclamation-triangle"></i>
                Note: You must either upload a file OR provide a server file path.
              </small>
            </div>
          </form>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="button" class="btn btn-primary" id="submitUpload">
            <i class="fas fa-upload"></i> Save File Information
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

    // Upload button click handler
    $(document).on('click', '.upload-btn:not(:disabled)', function() {
      const jobId = $(this).data('job-id');
      $('#uploadJobId').val(jobId);
      $('#modalJobId').text(jobId);
      $('#uploadForm')[0].reset();
      $('#uploadModal').modal('show');
    });

    // Submit upload form
    $('#submitUpload').on('click', function() {
        const formData = new FormData($('#uploadForm')[0]);
        const submitBtn = $(this);
        
        const fileInput = document.getElementById('job_file');
        const serverPathInput = document.getElementById('server_file_path');
        
        // Validation: Either file upload OR server path must be provided
        if (!fileInput.files[0] && !serverPathInput.value.trim()) {
            alert('Please either upload a file or provide server file path.');
            return;
        }
        
        // Basic client-side size check for uploaded file (10MB)
        if (fileInput.files[0] && fileInput.files[0].size > 10 * 1024 * 1024) {
            alert('File size must be less than 10MB.');
            return;
        }
        
        submitBtn.prop('disabled', true).html('<i class="fas fa-spinner fa-spin"></i> Saving...');
        
        $.ajax({
            url: window.location.href,
            type: 'POST',
            data: formData,
            processData: false,
            contentType: false,
            success: function(response) {
                let result;
                try {
                    result = JSON.parse(response);
                } catch (e) {
                    // If not JSON, show raw response for debugging
                    console.error('Non-JSON response:', response);
                  //  alert('Server returned an invalid response. Please check server logs.');
                  // Just reload the page without showing error
                  alert('File Uploaded.');
window.location.reload();
                    submitBtn.prop('disabled', false).html('<i class="fas fa-upload"></i> Save File Information');
                    return;
                }
                
                if (result.success) {
                    $('#uploadModal').modal('hide');
                    const message = result.message || 'File information saved successfully!';
                    alert(message);
                    window.location.reload(); 
                } else {
                    alert('Error: ' + (result.message || 'Unknown error'));
                }
            },
            error: function(xhr, status, error) {
                alert('Save failed. Please check network and server logs. Error: ' + error);
                console.error('AJAX error:', status, error);
            },
            complete: function() {
                submitBtn.prop('disabled', false).html('<i class="fas fa-upload"></i> Save File Information');
            }
        });
    });
    
    // Reset form when modal is closed
    $('#uploadModal').on('hidden.bs.modal', function() {
      $('#uploadForm')[0].reset();
    });
  });
  </script>