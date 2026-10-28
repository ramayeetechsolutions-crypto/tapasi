<br><br><br>
<?php
session_start();
error_reporting(E_ALL);
ini_set('display_errors', 0);

require 'db.php';
if ($mysqli->connect_error) {
    header('Content-Type: application/json');
    die(json_encode(["error" => "Connection failed: " . $mysqli->connect_error]));
}

function refValues($arr){
    if (version_compare(PHP_VERSION, '5.3.0', '>=')) {
        $refs = array();
        foreach($arr as $key => $value)
            $refs[$key] = &$arr[$key];
        return $refs;
    }
    return $arr;
}

$uid = $_SESSION['uid'] ?? 0;
$showEditButton = false;
$process_ids = [];
$job_id = isset($_GET['job_id']) ? (int)$_GET['job_id'] : 0;

// Handle AJAX requests (Update)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['id'])) {
    // Check if it's a completion request
    if (isset($_POST['complete']) && $_POST['complete'] === 'true') {
        handleCompleteRequest();
    } else {
        handleUpdateRequest();
    }
    exit;
}

// Handle data fetch request
if (isset($_GET['fetch']) && $_GET['fetch'] === 'data') {
    fetchData();
    exit;
}

// Get user permissions
if($uid){
    $stmt = $mysqli->prepare("SELECT process, user_role_id FROM employee_master WHERE employee_id=?");
    if ($stmt) {
        $stmt->bind_param("i", $uid);
        $stmt->execute();
        $stmt->bind_result($process, $user_role_id);
        if($stmt->fetch()){
            $process_ids = array_filter(array_map('trim', explode(',', $process)));
            
            // LE specific: User role 4 and process 2
            if($user_role_id == 4 && in_array('2', $process_ids)){ 
                $showEditButton = true;
            }
        }
        $stmt->close();
    }
}

$showEditButtonJson = json_encode($showEditButton);
$processIdsJson = json_encode($process_ids);

function handleUpdateRequest() {
    global $mysqli;
    $response = [
        'success' => false, 
        'error' => '', 
        'done_by' => null, 
        'le_date' => null, 
        'new_data' => null, 
        'status_updated' => false, 
        'tat' => null          
    ];
    
    if (!isset($_POST['id'])) {
        $response['error'] = 'Invalid request. Missing ID.';
        echo json_encode($response);
        exit;
    }
    
    $record_id = (int)$_POST['id'];
    $update_field = null;
    $new_value = null;
    $update_le_date = (int)($_POST['update_le_date'] ?? 0); 
    
    $employee_username = $_SESSION['username'] ?? 'Unknown User'; 
    $current_datetime = date('Y-m-d H:i:s');
    $is_word_count_update = false;
    
    // --- Determine which field to update ---
    $valid_fields = ['word_count', 'status'];
    foreach ($valid_fields as $field) {
        if (isset($_POST[$field])) {
            $update_field = $field;
            $new_value = $_POST[$field]; 
            break;
        }
    }
    
    if (!$update_field) {
        $response['error'] = 'No valid field provided for update.';
        echo json_encode($response);
        exit;
    }
    
    // --- Query Construction Setup ---
    $query_parts = [];
    $types = "";
    $params = [];
    
    // 1. Handle Word Count update
    if ($update_field === 'word_count') {
        $is_word_count_update = true;
        
        // Set word count
        $query_parts[] = "word_count = ?";
        $types .= "s";
        $params[] = $new_value;
        
        $response['status_updated'] = false;
        $update_le_date = 1;
    } 
    // 2. Handle Status update
    else if ($update_field === 'status') {
        // Allow setting to 'In Progress' but prevent 'Completed' via status field
        if ($new_value === 'Completed') {
            $response['error'] = 'Cannot set status to Completed via the status field. Please use the Manage button for final completion.';
            echo json_encode($response);
            exit;
        }
        
        $query_parts[] = "status = ?";
        $types .= "s";
        $params[] = $new_value;
        $response['status_updated'] = true;
        $update_le_date = 1;
    }
    
    try {
        $mysqli->begin_transaction(); 
        
        // Always update last_updated
        $query_parts[] = "last_updated = NOW()";
        
        // Always update done_by
        $query_parts[] = "done_by = ?";
        $types .= "s";
        $params[] = $employee_username;
        $response['done_by'] = $employee_username; 

        // 3. LE Date and TAT Update Logic
        if ($update_le_date === 1) { 
            
            // a. Set LE Date
            $query_parts[] = "le_date = ?"; 
            $types .= "s";
            $params[] = $current_datetime; 
            $response['le_date'] = date('Y-m-d H:i:s', strtotime($current_datetime)); 
            
            // b. Calculate and set TAT if this was a word count update
            if ($is_word_count_update) {
                
                // i. Fetch job_id from LE record
                $stmt_job_id = $mysqli->prepare("SELECT job_id FROM le WHERE id = ?"); 
                $stmt_job_id->bind_param("i", $record_id);
                $stmt_job_id->execute();
                $stmt_job_id->bind_result($job_id);
                $stmt_job_id->fetch();
                $stmt_job_id->close();
                
                $tat_value = null;
                $due_date_le_str = null;

                if ($job_id) {
                    // ii. Fetch due_date_le from JOBS table using job_id
                    $stmt_due_date = $mysqli->prepare("SELECT due_date_le FROM jobs WHERE job_id = ?"); 
                    $stmt_due_date->bind_param("i", $job_id); 
                    $stmt_due_date->execute();
                    $stmt_due_date->bind_result($due_date_le_str);
                    $stmt_due_date->fetch();
                    $stmt_due_date->close();
                }

                if ($due_date_le_str) {
                    // iii. Calculate TAT
                    $start_date = new DateTime($due_date_le_str);
                    $end_date = new DateTime($current_datetime);
                    $interval = $start_date->diff($end_date);
                    
                    $total_seconds = $interval->days * 86400 + $interval->h * 3600 + $interval->i * 60 + $interval->s;
                    $tat_days = $total_seconds / 86400;
                    
                    $tat_value = $interval->format('%r') . number_format($tat_days, 2); 
                    
                    // ADD TAT TO THE QUERY
                    $query_parts[] = "tat = ?"; 
                    $types .= "s";
                    $params[] = $tat_value; 
                    $response['tat'] = $tat_value;
                }
            }
        }
        
        // 4. Final Query Construction and Execution
        if ($is_word_count_update) {
            $query = "UPDATE le SET " . implode(', ', $query_parts) . ", status='In Progress' WHERE id = ?"; 
        } else {
            $query = "UPDATE le SET " . implode(', ', $query_parts) . " WHERE id = ?"; 
        }
        
        $types .= "i";
        $params[] = $record_id;

        $stmt = $mysqli->prepare($query);

        if (!$stmt) {
            throw new Exception("Preparation failed: " . $mysqli->error);
        }
        
        $bind_args = refValues(array_merge([$types], $params));

        if (!call_user_func_array([$stmt, 'bind_param'], $bind_args)) {
             throw new Exception("Bind parameter failed.");
        }

        if (!$stmt->execute()) {
            throw new Exception("Execution failed: " . $stmt->error);
        }
        
        $stmt->close();

        // 5. Update the jobs table if word count was entered
        if ($is_word_count_update) {
            if ($job_id) {
                $stmt_jobs = $mysqli->prepare("UPDATE jobs SET assigned_le = ? WHERE job_id = ?"); 
                if (!$stmt_jobs) {
                    throw new Exception("Jobs Prepare failed: " . $mysqli->error);
                }
                $stmt_jobs->bind_param("si", $employee_username, $job_id); 
                if (!$stmt_jobs->execute()) {
                    throw new Exception("Jobs Execute failed: " . $stmt_jobs->error);
                }
                $stmt_jobs->close();
            }
        }

        $mysqli->commit(); 
        
        $response['success'] = true;
        $response['new_data'] = $new_value;

    } catch (Exception $e) {
        $mysqli->rollback(); 
        $response['error'] = $e->getMessage();
    }
    
    if (ob_get_length()) ob_clean();
    header('Content-Type: application/json');
    echo json_encode($response);
    exit;
}

function handleCompleteRequest() {
    global $mysqli;
    $response = ['success' => false, 'error' => ''];
    
    if (!isset($_POST['id']) || !isset($_POST['job_id'])) {
        $response['error'] = 'Invalid request. Missing ID or Job ID.';
        echo json_encode($response);
        exit;
    }

    $record_id = (int)$_POST['id'];
    $job_id = $_POST['job_id'];
    $employee_username = $_SESSION['username'] ?? 'Unknown User';
    $current_datetime = date('Y-m-d H:i:s');

    try {
        $mysqli->begin_transaction();

        // 1. Fetch start date and word count from the LE record
        $stmt_date = $mysqli->prepare("SELECT date, word_count FROM le WHERE id = ?"); 
        $stmt_date->bind_param("i", $record_id);
        $stmt_date->execute();
        $stmt_date->bind_result($start_date_str, $word_count);
        $stmt_date->fetch();
        $stmt_date->close();
        
        if (empty($word_count) || $word_count <= 0) {
            throw new Exception("Word Count must be entered before final completion.");
        }
        
        // 2. Calculate TAT
        $tat_value = null;
        if ($start_date_str) {
            $start_date = new DateTime($start_date_str);
            $end_date = new DateTime($current_datetime);
            $interval = $start_date->diff($end_date);
            
            $total_seconds = $interval->days * 86400 + $interval->h * 3600 + $interval->i * 60 + $interval->s;
            $tat_days = $total_seconds / 86400;
            $tat_value = number_format($tat_days, 2);
        }
        
        // 3. Update the LE record to FINAL 'Completed' status
        $stmt_le = $mysqli->prepare("
            UPDATE le SET 
                status = 'Completed', 
                tat = ?,
                last_updated = NOW()
            WHERE id = ?");
            
        if (!$stmt_le) {
            throw new Exception("LE Prepare failed: " . $mysqli->error);
        }
        
        $stmt_le->bind_param("si", $tat_value, $record_id);
        
        if (!$stmt_le->execute()) {
            throw new Exception("LE Execute failed: " . $stmt_le->error);
        }
        $stmt_le->close();

        // 4. Update the jobs table
        $stmt_jobs = $mysqli->prepare("UPDATE jobs SET assigned_le = ? WHERE job_id = ?");
        
        if (!$stmt_jobs) {
            throw new Exception("Jobs Prepare failed: " . $mysqli->error);
        }

        $stmt_jobs->bind_param("si", $employee_username, $job_id); 
        
        if (!$stmt_jobs->execute()) {
            throw new Exception("Jobs Execute failed: " . $stmt_jobs->error);
        }
        $stmt_jobs->close();

        $mysqli->commit();
        $response['success'] = true;

    } catch (Exception $e) {
        $mysqli->rollback();
        $response['error'] = $e->getMessage();
    }
    
    if (ob_get_length()) ob_clean();
    header('Content-Type: application/json');
    echo json_encode($response);
    exit;
}

function fetchData() {
    global $mysqli;
    global $job_id;
    
    if (ob_get_length()) ob_clean();
    header('Content-Type: application/json');

    // Modified query to filter by job_id if provided
    if ($job_id > 0) {
        $query = "SELECT 
                    le.id, 
                    le.job_id, 
                    le.date, 
                    le.client, 
                    le.issue, 
                    le.articles, 
                    le.word_count,
                    le.tat,            
                    le.status, 
                    le.done_by, 
                    le.le_date,        
                    le.due_date AS due_date_le,
                    le.created_at,     
                    le.last_updated,
                    
                    -- Fetching CE data to display/compare --
                    ce.eq AS ce_eq, 
                    ce.tables_count AS ce_tables_count,
                    ce.images AS ce_images,
                    ce.ref AS ce_ref,
                    ce.word_count AS ce_word_count,
                    jm.job_name AS process
                  FROM le
                  JOIN ce ON le.job_id = ce.job_id
                  JOIN jobs j ON le.job_id = j.job_id
                  JOIN job_master jm ON j.process_id = jm.job_id
                  WHERE ce.status='Completed' AND le.status!='Completed' AND le.job_id = ?
                  ORDER BY le.status, le.id DESC";
        
        $stmt = $mysqli->prepare($query);
        $stmt->bind_param("i", $job_id);
        $stmt->execute();
        $result = $stmt->get_result();
    } else {
        $query = "SELECT 
                    le.id, 
                    le.job_id, 
                    le.date, 
                    le.client, 
                    le.issue, 
                    le.articles, 
                    le.word_count,
                    le.tat,            
                    le.status, 
                    le.done_by, 
                    le.le_date,        
                    le.due_date AS due_date_le,
                    le.created_at,     
                    le.last_updated,
                    
                    -- Fetching CE data to display/compare --
                    ce.eq AS ce_eq, 
                    ce.tables_count AS ce_tables_count,
                    ce.images AS ce_images,
                    ce.ref AS ce_ref,
                    ce.word_count AS ce_word_count,
                    jm.job_name AS process
                  FROM le
                  JOIN ce ON le.job_id = ce.job_id
                  JOIN jobs j ON le.job_id = j.job_id
                  JOIN job_master jm ON j.process_id = jm.job_id
                  WHERE ce.status='Completed' AND le.status!='Completed'
                  ORDER BY le.status, le.id DESC";
        
        $result = $mysqli->query($query);
    }
    
    $data = [];
    $current_date = new DateTime();

    if ($result) {
        while ($row = $result->fetch_assoc()) {
            foreach ($row as $key => $value) {
                if ($value === null) {
                    $row[$key] = '';
                }
            }

            // Calculate Days Left
            $days_left = '';
            if (!empty($row['due_date_le'])) {
                try {
                    $due_date = new DateTime($row['due_date_le']);
                    $interval = $current_date->diff($due_date);
                    $days_left = (int) $interval->format('%r%a'); 
                } catch (Exception $e) {
                    $days_left = 'N/A';
                }
            }
            $row['days_left'] = $days_left;

            $data[] = $row;
        }
        if (isset($stmt)) {
            $stmt->close();
        }
    }
    
    echo json_encode(['data' => $data]);
    exit;
}
require 'header.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>LE Table Management - Job ID: <?php echo $job_id > 0 ? $job_id : 'All Jobs'; ?></title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/jquery.dataTables.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <style>
        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }
        .spin {
            animation: spin 1s linear infinite;
        }
        td.editable { cursor: pointer; } 
        /* Status-based row colors */
        .status-need { background-color: #dc3545 !important; color: #fff !important; }    
        .status-progress { background-color: #ffc107 !important; color: #000 !important; } 
        .status-completed { background-color: #28a745 !important; color: #fff !important; } 
        /* Style for Manage button */
        .manage-record {
            font-size: 12px;
            padding: 3px 8px;
        }
    </style>
</head>
<body class="bg-light">
    <div class="container-fluid mt-4">
        <div class="card shadow">
            <div class="card-header bg-primary text-white d-flex justify-content-between align-items-center">
                <h3>LE Records <?php echo $job_id > 0 ? "- Job ID: $job_id" : ''; ?></h3>
                <?php if($job_id > 0): ?>
                    <a href="unassigned_jobs_le.php" class="btn btn-light btn-sm">View All Jobs</a>
                <?php else: ?>
                    <div class="input-group" style="width: 300px;">
                        <input type="number" id="jobIdFilter" class="form-control" placeholder="Enter Job ID" value="<?php echo $job_id > 0 ? $job_id : ''; ?>">
                        <button class="btn btn-light" onclick="filterByJobId()">Filter</button>
                    </div>
                <?php endif; ?>
            </div>
            <div class="card-body">
                <table id="leTable" class="table table-hover table-bordered w-100">
                    <thead>
                        <tr>
                            <th>ID</th>              <th>Job ID</th>          <th>Date</th>            <th>Client</th>          <th>Issue</th>           <th>Articles</th>        
                            <th>EQ</th>              <th>Images</th>          <th>Tables</th>          <th>Process</th>         
                            <th>CE Word Count</th>   <th>CE Ref</th>          <th>LE Word Count</th>   <th>Days Left</th>       
                            <th>Status</th>          <th>LE By</th>           <th>LE Date</th>         <th>Actions</th>
                        </tr>
                    </thead>
                </table>
            </div>
        </div>
    </div>

    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
    <script>
    function filterByJobId() {
        var jobId = $('#jobIdFilter').val();
        if(jobId && jobId > 0) {
            window.location.href = 'le.php?job_id=' + jobId;
        } else {
            window.location.href = 'le.php';
        }
    }

    $(document).ready(function(){
        var showEditButton = <?php echo $showEditButtonJson; ?>;

        console.log('Debug - showEditButton:', showEditButton); // Debug log

        var table = $('#leTable').DataTable({
            ajax: '?fetch=data<?php echo $job_id > 0 ? '&job_id=' . $job_id : ''; ?>',
            columns: [
                {data: 'id'},                 // 0 - ID
                {data: 'job_id'},             // 1 - Job ID
                {data: 'date'},               // 2 - Date
                {data: 'client'},             // 3 - Client
                {data: 'issue'},              // 4 - Issue
                {data: 'articles'},           // 5 - Articles
                
                {data: 'ce_eq'},              // 6 - EQ (FROM CE)
                {data: 'ce_images'},          // 7 - Images (FROM CE)
                {data: 'ce_tables_count'},    // 8 - Tables (FROM CE)
                {data: 'process'},            // 9 - Process
                {data: 'ce_word_count'},      // 10 - CE Word Count (FROM CE)
                {data: 'ce_ref'},             // 11 - CE Ref (FROM CE)
                {data: 'word_count'},         // 12 - LE Word Count (Editable)
                
                {data: 'days_left'},          // 13 - Days Left
                {data: 'status'},             // 14 - Status (Editable)
                {data: 'done_by'},            // 15 - LE By
                {data: 'le_date'},            // 16 - LE Date
                { // 17 - Actions (Manage Button Column)
                    data: null,
                    render: function(data, type, row) {
                        console.log('Debug - Rendering action column for row:', row.id, 'status:', row.status); // Debug log
                        
                        // Show Manage button for rows that are NOT completed
                        if (row.status !== 'Completed') {
                            var buttonText = row.status === 'In Progress' ? 'Complete' : 'Complete';
                            var buttonClass = row.status === 'In Progress' ? 'btn-warning' : 'btn-primary';
                            return '<button class="btn btn-sm ' + buttonClass + ' manage-record" data-id="'+row.id+'" data-jobid="'+row.job_id+'" title="'+buttonText+' Record"><i class="bi bi-gear"></i> ' + buttonText + '</button>';
                        }
                        return '<span class="badge bg-success">Completed</span>';
                    },
                    orderable: false,
                    searchable: false
                }
            ],
            columnDefs: [
                {
                    targets: [6, 7, 8, 10, 11], // CE Data columns
                    render: function(data, type, row) {
                        if (data === null || data === undefined || data === '') {
                            return '—';
                        }
                        return data;
                    }
                },
                {
                    targets: 14, // Status Column
                    orderable: false 
                },
                {
                    targets: 12, // LE Word Count Column
                    orderable: false
                }
            ],
            rowCallback: function(row, data){
                var isCompleted = (data.status === 'Completed');
                
                // Row Status Coloring
                $(row).removeClass('status-need status-progress status-completed');
                
                switch(data.status){
                    case 'Need to Assign': 
                        $(row).addClass('status-need'); 
                        break;
                    case 'In Progress': 
                        $(row).addClass('status-progress'); 
                        break;
                    case 'Completed': 
                        $(row).addClass('status-completed'); 
                        break;
                }

                // Editable Logic
                if (!isCompleted) {
                    // LE Word Count (Index 12)
                    $('td', row).eq(12).addClass('editable').attr('data-id', data.id); 
                    
                    // Status (Index 14) - Only editable if user has permission
                    if (showEditButton) {
                        $('td', row).eq(14).addClass('editable').attr('data-id', data.id); 
                    } else {
                        $('td', row).eq(14).removeClass('editable'); 
                    }
                } else {
                    $('td', row).eq(12).removeClass('editable'); 
                    $('td', row).eq(14).removeClass('editable'); 
                }
                
                // Debug: Log row data
                console.log('Row processed:', {
                    id: data.id,
                    status: data.status,
                    hasManageButton: data.status !== 'Completed'
                });
            }
        });

        // --------------------------------------------------------------------------------
        // 1. SINGLE CLICK HANDLER (Word Count / Status)
        // --------------------------------------------------------------------------------
        $('#leTable').on('click', 'td.editable', function(){
            if($(this).find('input, select').length > 0) return;

            var cell = table.cell(this);
            var oldValue = cell.data();
            var id = $(this).data('id');
            var colIndex = cell.index().column;

            // Field mapping
            var fieldMap = {12:'word_count', 14:'status'};
            var field = fieldMap[colIndex];
            
            if (!field) return; 

            // Create the appropriate input field
            if(field === 'status'){
                var options = '<option value="Need to Assign">Need to Assign</option>';
                options += '<option value="In Progress">In Progress</option>';
                options += '<option value="Completed">Completed</option>';
                
                $(this).html('<select class="form-select form-select-sm">'+options+'</select>');
                $(this).find('select').val(oldValue).focus();
            } else {
                // Word Count
                $(this).html('<input type="number" min="0" class="form-control form-control-sm" value="'+oldValue+'">');
                $(this).find('input').focus().select();
            }

            $(this).find('input, select').on('blur keyup', function(e){
                if(e.type === 'blur' || e.key === 'Enter'){
                    var newValue = $(this).val();
                    
                    if(String(newValue).trim() === String(oldValue).trim()){
                        table.row(cell.index().row).invalidate().draw();
                        return;
                    }
                    
                    var postData = {id: id, [field]: newValue}; 
                    
                    // Update date for word count or status changes
                    if (field === 'word_count' || field === 'status') {
                        postData.update_le_date = 1; 
                    }

                    $.post('', postData, function(res){ 
                        if(res.success){
                            cell.data(newValue).draw();
                            
                            // Update LE By and LE Date
                            if (res.done_by) { 
                                table.cell(cell.index().row, 15).data(res.done_by).draw(); 
                            }
                            if (res.le_date) { 
                                table.cell(cell.index().row, 16).data(res.le_date).draw(); 
                            } 
                            
                            // Reload the row to update action button
                            table.row(cell.index().row).invalidate().draw(); 
                            
                            // Show success message
                            if (field === 'word_count') {
                                alert('Word count updated successfully!');
                            }
                        } else {
                            alert(res.error || 'Update failed');
                            table.row(cell.index().row).invalidate().draw();
                        }
                    }, 'json').fail(function() {
                        alert('Server communication failed for update.');
                        table.row(cell.index().row).invalidate().draw();
                    });
                }
            });
        });

        // --------------------------------------------------------------------------------
        // 2. MANAGE BUTTON HANDLER (Complete Record)
        // --------------------------------------------------------------------------------
        $('#leTable').on('click', '.manage-record', function(e) {
            e.stopPropagation();
            
            var button = $(this);
            var id = button.data('id');
            var jobId = button.data('jobid');
            var rowData = table.row(button.closest('tr')).data();
            var actionType = button.text().includes('Complete') ? 'Complete' : 'Complete';

            console.log('Manage button clicked:', {
                id: id,
                jobId: jobId,
                status: rowData.status,
                actionType: actionType
            });

            if (actionType === 'Complete') {
                // For completing the record
                if (!rowData.word_count || parseInt(rowData.word_count) <= 0) {
                    alert('Please enter the Word Count before marking the record as Completed.');
                    return;
                }
                
                if (!confirm('Are you sure you want to mark Job ID ' + jobId + ' as Completed? This action is final.')) {
                    return;
                }

                button.prop('disabled', true).html('<i class="bi bi-arrow-clockwise spin"></i>');

                $.post('', {
                    id: id, 
                    job_id: jobId,
                    complete: 'true'
                }, function(res) { 
                    if (res.success) {
                        alert('Job ID ' + jobId + ' successfully completed!');
                        table.ajax.reload(null, false); 
                    } else {
                        alert('Completion failed: ' + (res.error || 'Unknown error'));
                        button.prop('disabled', false).html('<i class="bi bi-gear"></i> Complete');
                    }
                }, 'json').fail(function() {
                    alert('Server communication failed.');
                    button.prop('disabled', false).html('<i class="bi bi-gear"></i> Complete');
                });
            } else {
                // For starting the record (changing status from Need to Assign to In Progress)
                if (!confirm('Are you sure you want to start working on Job ID ' + jobId + '?')) {
                    return;
                }

                button.prop('disabled', true).html('<i class="bi bi-arrow-clockwise spin"></i>');

                $.post('', {
                    id: id,
                    status: 'In Progress',
                    update_le_date: 1
                }, function(res) { 
                    if (res.success) {
                        alert('Job ID ' + jobId + ' is now In Progress!');
                        table.ajax.reload(null, false); 
                    } else {
                        alert('Failed to start job: ' + (res.error || 'Unknown error'));
                        button.prop('disabled', false).html('<i class="bi bi-gear"></i> Complete');
                    }
                }, 'json').fail(function() {
                    alert('Server communication failed.');
                    button.prop('disabled', false).html('<i class="bi bi-gear"></i> Complete');
                });
            }
        });
    });
    </script>
</body>
</html>