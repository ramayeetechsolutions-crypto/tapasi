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
$employee_name = $_SESSION['employee_name'] ?? 'Unknown User';

// Handle AJAX requests (Update)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['id'])) {
    handleUpdateRequest();
    exit;
}

// Handle data fetch request
if (isset($_GET['fetch']) && $_GET['fetch'] === 'data') {
    fetchData();
    exit;
}

// Get user permissions
if($uid){
    $stmt = $mysqli->prepare("SELECT process, user_role_id, employee_name FROM employee_master WHERE employee_id=?");
    if ($stmt) {
        $stmt->bind_param("i", $uid);
        $stmt->execute();
        $stmt->bind_result($process, $user_role_id, $fetched_employee_name);
        if($stmt->fetch()){
            $process_ids = array_filter(array_map('trim', explode(',', $process)));
            
            $allowed_processes = ['18', '19', '38'];
            if (count(array_intersect($process_ids, $allowed_processes)) > 0) {
                $showEditButton = true;
            }
            $_SESSION['employee_name'] = $fetched_employee_name;
            $employee_name = $fetched_employee_name;
        }
        $stmt->close();
    }
}

$showEditButtonJson = json_encode($showEditButton);
$userProcessIdsJson = json_encode($process_ids);
$employeeNameJson = json_encode($employee_name);

function handleUpdateRequest() {
    global $mysqli;
    $response = [
        'success' => false, 
        'error' => '', 
        'done_by' => null, 
        'pag_date' => null,
        'new_data' => null, 
        'status_updated' => false
    ];
    
    if (!isset($_POST['id'])) {
        $response['error'] = 'Invalid request. Missing ID.';
        echo json_encode($response);
        exit;
    }

    $record_job_id = (int)$_POST['id']; 
    
    // --- Field Detection ---
    $field = null; 
    $value = null; 
    $status = null;
    foreach ($_POST as $key => $val) {
        if (in_array($key, ['pages', 'pag_images', 'pag_tables_count', 'status'])) {
            $field = $key;
            $value = $val;
            if ($key === 'status') $status = $val;
            break;
        }
    }

    $set_check_flag = (int)($_POST['set_check_flag'] ?? 0); 
    $explicit_completion_update = (int)($_POST['update_pag_date'] ?? 0); 

    $employee_username = $_SESSION['username'] ?? 'Unknown User'; 
    $session_username = $_SESSION['username'] ?? 'Unknown User'; 
    $current_datetime = date('Y-m-d H:i:s');
    $current_date_only = date('d-m'); 

    $is_core_pag_field = in_array($field, ['pages', 'pag_images', 'pag_tables_count']);
    $is_status_update = ($status !== null);

    if (!$field) {
        $response['error'] = 'No valid PAG field provided for update.';
        echo json_encode($response);
        exit;
    }

    $db_field_map = [
        'pages' => 'pages',
        'pag_images' => 'images',        
        'pag_tables_count' => 'tables_count',
        'status' => 'status'
    ];
    $db_update_field = $db_field_map[$field] ?? $field; 

    $check_field = $is_core_pag_field ? $db_update_field . '_check' : null;
    $update_tracking_info = false;
    $status_update_needed = false;
    $new_value_for_db = $value;

    try {
        $mysqli->begin_transaction(); 
        
        $query_parts = [];
        $types = "";
        $params = [];
        
        // --- Core PAG Field Updates (Assignment or Completion) ---
        if ($is_core_pag_field) {
            
            if ($set_check_flag === 1) { // Flag 1 Assignment (Red -> Yellow)
                
                // Check current status for 'In Progress' status update flag
                $stmt_check = $mysqli->prepare("SELECT status FROM pag WHERE job_id = ?");
                $stmt_check->bind_param("i", $record_job_id);
                $stmt_check->execute();
                $stmt_check->bind_result($current_status);
                $stmt_check->fetch();
                $stmt_check->close();

                if ($current_status === 'Need to Assign' || $current_status === null) {
                    $status_update_needed = true;
                    $response['status_updated'] = true; 
                }
                
                $response['new_data'] = "{$employee_username} / In Progress"; 
                $update_tracking_info = true;
                
                // Update assigned_pag in jobs table
                $stmt_jobs_update = $mysqli->prepare("UPDATE jobs SET assigned_pag = ? WHERE job_id = ?");
                if ($stmt_jobs_update) {
                    $stmt_jobs_update->bind_param("si", $session_username, $record_job_id);
                    if (!$stmt_jobs_update->execute()) {
                        throw new Exception("Failed to update jobs.assigned_pag: " . $stmt_jobs_update->error);
                    }
                    $stmt_jobs_update->close();
                }
                
            } else if ($set_check_flag === 2) { // Flag 2 Completion (Yellow -> Green or Manual Red)
                
                // --- CRITICAL VALUE FORMATTING ---
                if ($field === 'pages') {
                     // 1. Pages: Update only the numeric value (no formatting)
                     $new_value_for_db = (int)$value;
                     $response['new_data'] = $new_value_for_db; 
                } elseif ($explicit_completion_update === 1) {
                    // 2. Others (Images/Tables): Use the full formatted string
                    $new_value_for_db = "{$employee_username}/{$current_date_only}/{$value}"; 
                    $response['new_data'] = $new_value_for_db; 
                } else {
                    // Fallback (shouldn't happen with correct JS)
                    $new_value_for_db = $value;
                    $response['new_data'] = $new_value_for_db; 
                }
                // --- END CRITICAL VALUE FORMATTING ---
                
                $update_tracking_info = true;
            }

            // Add the main field ONLY IF it's completion (Flag 2) or a Red cell manual update for Pages
            if ($set_check_flag === 2 || ($set_check_flag === 0 && $field === 'pages')) { 
                $query_parts[] = "{$db_update_field} = ?";
                $types .= "s"; 
                $params[] = $new_value_for_db; 
            }

            // Check Flag Update Logic
            if ($check_field && $set_check_flag > 0) {
                $query_parts[] = "{$check_field} = ?";
                $types .= "i";
                $params[] = $set_check_flag;
            }
        }
        
        // --- Dedicated Status Update ---
        if ($is_status_update) {
            $query_parts[] = "status = ?";
            $types .= "s";
            $params[] = $value; 
            $update_tracking_info = true;
        }

        // --- Tracking Info Update Logic (Done By & PAG Date/Time) ---
        if ($update_tracking_info) {
            $query_parts[] = "done_by = ?";
            $types .= "s";
            $params[] = $employee_username;
            $response['done_by'] = $employee_username; 

            // pag_date is updated here for assignment/in-progress tracking
            $query_parts[] = "pag_date = ?";
            $types .= "s";
            $params[] = $current_datetime; 
            $response['pag_date'] = date('Y-m-d');
        }
        
        // --- Status Update Logic (from assignment) ---
        if ($status_update_needed) {
            $query_parts[] = "status = ?";
            $types .= "s";
            $params[] = 'In Progress';
        }

        // Final Query Construction and Execution (for PAG table)
        if (empty($query_parts)) {
            throw new Exception("No fields to update in PAG table.");
        }
        
        $query = "UPDATE pag SET " . implode(', ', $query_parts) . " WHERE job_id = ?"; 
        $types .= "i";
        $params[] = $record_job_id;

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
        
        // FINAL COMPLETION CHECK (ALL PAG checks are 2)
        if ($set_check_flag === 2) {
            $check_sql = "SELECT pages_check, images_check, tables_count_check FROM pag WHERE job_id = ?";
            $stmt_check_comp = $mysqli->prepare($check_sql);
            $stmt_check_comp->bind_param("i", $record_job_id);
            $stmt_check_comp->execute();
            $res = $stmt_check_comp->get_result();
            $row = $res->fetch_assoc();
            $stmt_check_comp->close();

            if ($row && 
                (int)$row['pages_check'] === 2 && 
                (int)$row['images_check'] === 2 && 
                (int)$row['tables_count_check'] === 2) {
                
                // FETCH DUE DATE FROM JOBS TABLE
                $due_date_pag = null;
                $stmt_fetch_due_date = $mysqli->prepare("SELECT due_date_pag FROM jobs WHERE job_id = ?");
                if ($stmt_fetch_due_date) {
                    $stmt_fetch_due_date->bind_param("i", $record_job_id);
                    $stmt_fetch_due_date->execute();
                    $stmt_fetch_due_date->bind_result($due_date_pag);
                    $stmt_fetch_due_date->fetch();
                    $stmt_fetch_due_date->close();
                }

                $pag_tat_days = 0;
                $current_completion_datetime_obj = new DateTime($current_datetime);

                // CALCULATE TAT IN DAYS
                if ($due_date_pag) {
                    try {
                        $due_date_obj = new DateTime($due_date_pag);
                        $seconds_diff = $current_completion_datetime_obj->getTimestamp() - $due_date_obj->getTimestamp();
                        $pag_tat_days = round($seconds_diff / (60 * 60 * 24), 2);
                    } catch (Exception $e) {
                        error_log("TAT calculation failed for job_id $record_job_id: " . $e->getMessage());
                    }
                }

                // UPDATE PAG TABLE WITH FINAL STATUS, DATE, AND TAT
                $update_sql = "UPDATE pag SET status = 'Completed', pag_date = ?, tat = ? WHERE job_id = ?";
                $stmt_update = $mysqli->prepare($update_sql);
                
                if ($stmt_update) {
                    $stmt_update->bind_param("sdi", $current_datetime, $pag_tat_days, $record_job_id);
                    if ($stmt_update->execute()) {
                         $response['status_updated'] = true;
                         $response['pag_date'] = $current_completion_datetime_obj->format('Y-m-d H:i:s');
                    }
                    $stmt_update->close();
                }
            }
        }
        
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

    if ($job_id > 0) {
        $query = "
            SELECT 
                job_id, 
                date AS date, 
                client, 
                issue,  
                articles, 
                pages, 
                images AS pag_images,        
                tables_count AS pag_tables_count, 
                IFNULL(pages_check, 0) AS pages_check, 
                IFNULL(images_check, 0) AS images_check, 
                IFNULL(tables_count_check, 0) AS tables_count_check,
                status, 
                done_by, 
                pag_date
            FROM pag
            WHERE (status != 'Completed' OR status IS NULL) AND job_id = ?
            ORDER BY job_id DESC
        ";
        
        $stmt = $mysqli->prepare($query);
        $stmt->bind_param("i", $job_id);
        $stmt->execute();
        $result = $stmt->get_result();
    } else {
        $query = "
            SELECT 
                job_id, 
                date AS date, 
                client, 
                issue,  
                articles, 
                pages, 
                images AS pag_images,        
                tables_count AS pag_tables_count, 
                IFNULL(pages_check, 0) AS pages_check, 
                IFNULL(images_check, 0) AS images_check, 
                IFNULL(tables_count_check, 0) AS tables_count_check,
                status, 
                done_by, 
                pag_date
            FROM pag
            WHERE status != 'Completed' OR status IS NULL
            ORDER BY job_id DESC
        ";
        
        $result = $mysqli->query($query);
    }
    
    $data = [];
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            foreach ($row as $key => $value) {
                if ($value === null) {
                    if ($key === 'status') {
                        $row[$key] = 'Need to Assign';
                    } else {
                        $row[$key] = '';
                    }
                }
            }
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
    <title>Paginator Records - Job ID: <?php echo $job_id > 0 ? $job_id : 'All Jobs'; ?></title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/jquery.dataTables.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <style>
        @keyframes spin { 0% { transform: rotate(0deg); } 100% { transform: rotate(360deg); } }
        .spin { animation: spin 1s linear infinite; }
        td.editable { cursor: pointer; } 
        .status-need { background-color: #dc3545 !important; color: #fff !important; }    
        .status-progress { background-color: #ffc107 !important; color: #000 !important; } 
        .status-completed { background-color: #28a745 !important; color: #fff !important; } 
        .red-bg { background-color: #f8d7da !important; color: #721c24 !important; }      
        .yellow-bg { background-color: #fffae0 !important; color: #664d03; }  
        .green-bg { background-color: #d4edda !important; color: #155724; }  
        td.cell-container { padding-right: 5px !important; }
        .cell-content-wrapper { position:relative; width:100%; height:100%; padding-right:35px; } 
        tr.status-need td.red-bg { background-color: #f8d7da !important; color: #721c24 !important; } 
    </style>
</head>
<body class="bg-light">
    <div class="container-fluid mt-4">
        <div class="card shadow">
            <div class="card-header bg-primary text-white d-flex justify-content-between align-items-center">
                <h3>Paginator Records <?php echo $job_id > 0 ? "- Job ID: $job_id" : ''; ?></h3>
                <?php if($job_id > 0): ?>
                    <a href="unassigned_jobs_pag.php" class="btn btn-light btn-sm">View All Jobs</a>
                <?php else: ?>
                    <div class="input-group" style="width: 300px;">
                        <input type="number" id="jobIdFilter" class="form-control" placeholder="Enter Job ID" value="<?php echo $job_id > 0 ? $job_id : ''; ?>">
                        <button class="btn btn-light" onclick="filterByJobId()">Filter</button>
                    </div>
                <?php endif; ?>
            </div>
            <div class="card-body">
                <table id="pagTable" class="table table-hover table-bordered w-100">
                    <thead>
                        <tr>
                            <th>Job ID</th>      <th>Date</th>        <th>Client</th>      <th>Issue</th>       <th>Articles</th>    
                            <th>Pages (PAG)</th> <th>Images (PAG)</th><th>Tables (PAG)</th><th>Status</th>      
                            <th>PAG By</th>      <th>PAG Date</th>    <th></th>
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
            window.location.href = 'pag.php?job_id=' + jobId;
        } else {
            window.location.href = 'pag.php';
        }
    }

    $(document).ready(function(){
        var showEditButton = <?php echo $showEditButtonJson; ?>;
        var userProcessIds = <?php echo $userProcessIdsJson; ?>;
        var employeeName = <?php echo $employeeNameJson; ?>;

        console.log('Debug - PAG Permissions:', {
            showEditButton: showEditButton,
            userProcessIds: userProcessIds,
            employeeName: employeeName
        });

        var table = $('#pagTable').DataTable({
            ajax: '?fetch=data<?php echo $job_id > 0 ? '&job_id=' . $job_id : ''; ?>',
            columns: [
                {data: 'job_id'},          // 0
                { // 1 - Date with MM/DD/YYYY format
                    data: 'date',
                    render: function(data, type, row) {
                        if (type === 'display' || type === 'filter') {
                            if (data) {
                                var dateParts = data.split('-');
                                if (dateParts.length === 3) {
                                    return dateParts[1] + '-' + dateParts[2] + '-' + dateParts[0];
                                }
                            }
                        }
                        return data;
                    }
                },
                {data: 'client'},          // 2
                {data: 'issue'},           // 3
                {data: 'articles'},        // 4
                {data: 'pages'},           // 5 - Pages (PAG)
                {data: 'pag_images'},      // 6 - Images (PAG)
                {data: 'pag_tables_count'},// 7 - Tables (PAG)
                {data: 'status'},          // 8 - PAG Status
                {data: 'done_by'},         // 9 - PAG Done By
                {data: 'pag_date'},        // 10 - PAG Date
                {data: null}               // 11 - Action Column
            ],
            columnDefs: [
                {
                    targets: 11, // Action Column
                    data: null,
                    render: function(data, type, row) {
                        return ''; 
                    },
                    orderable: false,
                    searchable: false
                }
            ],
            rowCallback: function(row, data){
                var isCompleted = (data.status === 'Completed');
                
                // 1. Row Status Coloring
                $(row).removeClass('status-need status-progress status-completed text-dark text-white');
                switch(data.status){
                    case 'Need to Assign': $(row).addClass('status-need'); break;
                    case 'In Progress': $(row).addClass('status-progress'); break;
                    case 'Completed': $(row).addClass('status-completed'); break;
                }

                console.log('Debug - Row data:', {
                    job_id: data.job_id,
                    status: data.status,
                    pages_check: data.pages_check,
                    images_check: data.images_check,
                    tables_check: data.tables_count_check
                });

                // 2. Cell Coloring and Editable Logic for PAG fields (Cols 5, 6, 7)
                var pagCols = [
                    // Col Index : DB Field : Check Field : Required Process ID
                    { index: 5, field: 'pages', check_field: 'pages_check', required_process: '38' },       
                    { index: 6, field: 'pag_images', check_field: 'images_check', required_process: '19' }, 
                    { index: 7, field: 'pag_tables_count', check_field: 'tables_count_check', required_process: '18' } 
                ];
                
                pagCols.forEach(function(col) {
                    var cell = $('td', row).eq(col.index);
                    var check_value = parseInt(data[col.check_field]) || 0; 
                    var originalData = data[col.field];
                    
                    cell.empty().text(originalData); 
                    cell.removeClass('editable red-bg green-bg yellow-bg cell-container');
                    cell.css('padding-right', ''); 
                    
                    var isAuthorizedForProcess = userProcessIds.includes(col.required_process);

                    // Pages (Col 5) does NOT get a button, Images/Tables (Col 6,7) get buttons
                    var hasAssignButton = (col.index !== 5); 

                    if (!isCompleted && showEditButton) {
                        
                        if (check_value === 0) {
                            // FLAG 0: RED CELL (Need to Assign/Update)
                            cell.addClass('red-bg'); 
                            cell.attr('data-id', data.job_id);
                            cell.attr('data-field', col.field); 

                            if (isAuthorizedForProcess) {
                                 cell.addClass('editable');
                                 
                                 if (hasAssignButton) {
                                    // Add button for Images (6) and Tables (7)
                                    cell.addClass('cell-container');
                                    cell.css('padding-right', '5px'); 
                                    
                                    var btnHtml = '<button class="btn btn-sm btn-primary assign-cell-btn" data-id="'+data.job_id+'" data-field="'+col.field+'" title="Assign this item to me" style="position:absolute; top:2px; right:2px; padding: 2px 5px;"><i class="bi bi-person-fill-add"></i></button>';
                                    
                                    // Wrap content to allow space for the button
                                    cell.empty().append('<div class="cell-content-wrapper">' + originalData + btnHtml + '</div>');
                                 } else {
                                    // Pages (5) is editable directly, no button
                                    cell.empty().text(originalData); 
                                 }
                            }
                        } else if (check_value === 1) { 
                            // FLAG 1: YELLOW CELL (Assigned, Editable) - Same for all
                            var assignedText = data.done_by ? data.done_by + ' / In Progress' : originalData;
                            cell.empty().text(assignedText); 
                            
                            cell.attr('data-original-value', originalData); 
                            cell.addClass('yellow-bg');
                            if (isAuthorizedForProcess) {
                                cell.addClass('editable'); 
                            }
                            cell.attr('data-id', data.job_id);
                            cell.attr('data-field', col.field); 
                        } else if (check_value === 2) { 
                            // FLAG 2: GREEN CELL (Completed, Not editable)
                            cell.addClass('green-bg'); 
                        }
                    } else if (check_value === 2 || isCompleted) {
                        cell.addClass('green-bg'); 
                    }
                });
                
                // 3. Status (Column 8) - Editable
                if (!isCompleted && showEditButton) {
                    $('td', row).eq(8).addClass('editable').attr('data-id', data.job_id); 
                }
                
                // Set job_id as data-id for all cells in the row
                $(row).find('td').attr('data-id', data.job_id);
            }
        });

        // --------------------------------------------------------------------------------
        // 1. YELLOW CELL HANDLER (Flag 1 -> Flag 2: Final Entry)
        // --------------------------------------------------------------------------------
        $('#pagTable').on('click', 'td.yellow-bg.editable', function(){
            if($(this).find('input, select').length > 0) return;

            var cell = table.cell(this);
            var originalValue = $(this).data('original-value'); 
            var id = $(this).data('id');
            var field = $(this).data('field');
            
            if (!field) return; 
            
            var initialValue = String(originalValue).trim() || '0'; 
            
            $(this).html('<input type="text" class="form-control form-control-sm" value="'+initialValue+'">');
            $(this).find('input').focus().select();

            $(this).find('input').on('blur keyup', function(e){
                if(e.type === 'blur' || e.key === 'Enter'){
                    var newValue = $(this).val();
                    
                    if(String(newValue).trim() === String(initialValue).trim()){
                        table.row(cell.index().row).invalidate().draw(); 
                        return;
                    }

                    var postData = {
                        id: id,
                        [field]: newValue,
                        set_check_flag: 2,
                        update_pag_date: 1
                    };
                    
                    $.post('', postData, function(res){
                        if(res.success){
                            table.cell(cell.index().row, 9).data(res.done_by).draw();
                            table.cell(cell.index().row, 10).data(res.pag_date).draw();
                            table.cell(cell.index().row, cell.index().column).data(res.new_data).draw();
                            table.ajax.reload(null, false); 
                        } else {
                            alert(res.error || 'Update failed on final entry');
                            table.ajax.reload(null, false);
                        }
                    }, 'json').fail(function(jqXHR, textStatus, errorThrown) {
                         alert('Server communication failed for final entry update.');
                         console.error("AJAX Error:", jqXHR.responseText);
                         table.ajax.reload(null, false);
                    });
                }
            });
        });

        // --------------------------------------------------------------------------------
        // 2. RED CELL MANUAL ENTRY (Flag 0 -> Flag 2) - For Pages (Col 5)
        // --------------------------------------------------------------------------------
        $('#pagTable').on('click', 'td.red-bg.editable:not(.cell-container)', function(){
            if($(this).find('input, select').length > 0) return;

            var cell = table.cell(this);
            var initialValue = String(cell.data()).trim() || '0'; 
            var id = $(this).data('id');
            var field = $(this).data('field');

            if (!field || field !== 'pages') return; 

            $(this).html('<input type="text" class="form-control form-control-sm" value="'+initialValue+'">');
            $(this).find('input').focus().select();

            $(this).find('input').on('blur keyup', function(e){
                if(e.type === 'blur' || e.key === 'Enter'){
                    var newValue = $(this).val();
                    
                    if(String(newValue).trim() === String(initialValue).trim()){
                        table.row(cell.index().row).invalidate().draw(); 
                        return;
                    }

                    var postData = {
                        id: id,
                        [field]: newValue,
                        set_check_flag: 2,
                        update_pag_date: 1
                    };
                    
                    $.post('', postData, function(res){
                        if(res.success){
                            table.cell(cell.index().row, 9).data(res.done_by).draw();
                            table.cell(cell.index().row, 10).data(res.pag_date).draw();
                            if(res.status_updated){ 
                                table.cell(cell.index().row, 8).data('Completed').draw(); 
                            }
                            table.ajax.reload(null, false); 
                        } else {
                            alert(res.error || 'Update failed on direct red cell entry.');
                            table.ajax.reload(null, false);
                        }
                    }, 'json').fail(function(jqXHR, textStatus, errorThrown) {
                         alert('Server communication failed for direct red cell update.');
                         console.error("AJAX Error:", jqXHR.responseText);
                         table.ajax.reload(null, false);
                    });
                }
            });
        });

        // --------------------------------------------------------------------------------
        // 3. CELL-LEVEL ASSIGN TO ME HANDLER (Flag 0 -> Flag 1) - Used by Images/Tables
        // --------------------------------------------------------------------------------
        $('#pagTable').on('click', '.assign-cell-btn', function(e) {
            e.stopPropagation(); 

            var button = $(this);
            var cellElement = button.closest('td');
            var cell = table.cell(cellElement);
            var id = button.data('id');
            var field = button.data('field'); 
            
            button.prop('disabled', true).html('<i class="bi bi-arrow-clockwise spin"></i>');

            var postData = {
                id: id,
                [field]: '0',
                set_check_flag: 1
            };
            
            $.post('', postData, function(res){
                if(res.success){
                    table.cell(cell.index().row, 9).data(res.done_by).draw();
                    table.cell(cell.index().row, 10).data(res.pag_date).draw();
                    if(res.status_updated){ 
                        table.cell(cell.index().row, 8).data('In Progress'); 
                    }
                    table.ajax.reload(null, false);
                    
                } else {
                    alert('PAG Cell assignment failed. Error: ' + (res.error || 'Check server logs.')); 
                    table.ajax.reload(null, false); 
                }
            }, 'json').fail(function(jqXHR, textStatus, errorThrown) {
                alert('Server communication failed for single click update.');
                console.error("AJAX Error:", jqXHR.responseText);
                table.ajax.reload(null, false); 
            }).always(function() {
                button.prop('disabled', false).html('<i class="bi bi-person-fill-add"></i>');
            });
        });

        // --------------------------------------------------------------------------------
        // 4. STATUS UPDATE HANDLER
        // --------------------------------------------------------------------------------
        $('#pagTable').on('click', 'td.editable:not(.red-bg):not(.yellow-bg)', function(){
            if($(this).find('input, select').length > 0) return;

            var cell = table.cell(this);
            var oldValue = cell.data();
            var id = $(this).data('id');
            var colIndex = cell.index().column;

            if (colIndex !== 8) return; // Only handle status column (8)

            var statusOptions = ['Need to Assign', 'In Progress', 'Completed'];
            var selectHtml = '<select class="form-select form-select-sm">';
            statusOptions.forEach(function(option) {
                var selected = (option === oldValue) ? 'selected' : '';
                selectHtml += '<option value="'+option+'" '+selected+'>'+option+'</option>';
            });
            selectHtml += '</select>';
            
            cell.data(selectHtml); 

            var selectElement = $(this).find('select');
            selectElement.focus();

            selectElement.on('change blur', function(e) {
                var newValue = $(this).val();
                
                if (e.type === 'blur' && newValue === oldValue) {
                    table.row(cell.index().row).invalidate().draw(); 
                    return;
                }

                var postData = {
                    id: id, 
                    status: newValue
                };
                
                $.post('', postData, function(res){
                    if(res.success){
                        cell.data(newValue).draw();
                        table.row(cell.index().row).invalidate().draw(); 
                    } else {
                        alert(res.error || 'Status update failed.');
                        table.row(cell.index().row).invalidate().draw();
                    }
                }, 'json').fail(function() {
                    alert('Server communication failed for status update.');
                    table.row(cell.index().row).invalidate().draw();
                });
            });
        });
    });
    </script>
</body>
</html>