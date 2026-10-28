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
    $stmt = $mysqli->prepare("SELECT process, user_role_id FROM employee_master WHERE employee_id=?");
    if ($stmt) {
        $stmt->bind_param("i", $uid);
        $stmt->execute();
        $stmt->bind_result($process, $user_role_id);
        if($stmt->fetch()){
            $process_ids = array_filter(array_map('trim', explode(',', $process)));
            $allowed_processes = ['7', '8', '9', '10', '11'];
            if (count(array_intersect($process_ids, $allowed_processes)) > 0) {
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
    $response = ['success' => false, 'error' => '', 'done_by' => null, 'ce_date' => null, 'new_data' => null, 'status_updated' => false];
    
    $record_id = (int)$_POST['id'];
    $update_field = null;
    $new_value = null;
    $set_check_flag = (int)($_POST['set_check_flag'] ?? 0); 
    $explicit_completion_update = (int)($_POST['update_ce_date'] ?? 0);
    
    $employee_username = $_SESSION['username'] ?? 'Unknown User'; 
    $current_datetime = date('Y-m-d H:i:s');
    $current_date_only = date('d-m');
    
    // --- Determine which field to update ---
    $valid_fields = ['eq', 'images', 'tables_count', 'word_count', 'ref', 'status'];
    $is_core_completion_field = false;
    $is_status_update = false;
    $is_word_count_update = false;
    
    foreach ($valid_fields as $field) {
        if (isset($_POST[$field])) {
            $update_field = $field;
            $new_value = $_POST[$field]; 
            
            if (in_array($update_field, ['eq', 'images', 'tables_count', 'ref'])) {
                $is_core_completion_field = true;
            }
            if ($update_field === 'status') {
                $is_status_update = true;
            }
            if ($update_field === 'word_count') {
                $is_word_count_update = true;
            }
            break;
        }
    }
    
    if (!$update_field) {
        echo json_encode(['success' => false, 'error' => 'No field provided']);
        exit;
    }
    
    // Map the data field name to the check flag field name
    $check_field = null;
    $is_check_field = $is_core_completion_field; 
    if ($is_check_field) {
        $check_field = ($update_field === 'tables_count') ? 'tables_count_check' : $update_field . '_check';
    }
    
    $update_tracking_info = $is_word_count_update;
    $status_update_needed = false;
    
    try {
        $mysqli->begin_transaction();
        
        $query_parts = [];
        $types = "";
        $params = [];
        
        // --- Step 1: Handle Flag 1 Assignment (Flag 0 -> Flag 1) ---
        if ($is_check_field && $set_check_flag === 1) {
            
            // Check current status
            $stmt_check = $mysqli->prepare("SELECT status FROM ce WHERE id = ?");
            $stmt_check->bind_param("i", $record_id);
            $stmt_check->execute();
            $stmt_check->bind_result($current_status);
            $stmt_check->fetch();
            $stmt_check->close();
            
            if ($current_status === 'Need to Assign') {
                $status_update_needed = true;
                $response['status_updated'] = true; 
            }
            
            // Set the cell value to the assignment string
            $new_value = "{$employee_username} / In Progress"; 
            $response['new_data'] = $new_value; 
            
        // --- Step 2: Handle Flag 2 Completion ---
        } else if ($is_check_field && $set_check_flag === 2) {
            
            if ($explicit_completion_update === 1) {
                $new_value_formatted = "{$employee_username}/{$current_date_only}/{$new_value}"; 
                $new_value = $new_value_formatted; 
                $response['new_data'] = $new_value_formatted;
            } else {
                $response['new_data'] = $new_value; 
            }
        }
        
        // Add the main updated field to the query
        $query_parts[] = "{$update_field} = ?";
        $types .= "s";
        $params[] = $new_value; 
        
        // Update done_by and ce_date ONLY IF word_count was updated
        if ($update_tracking_info) {
            $query_parts[] = "done_by = ?";
            $types .= "s";
            $params[] = $employee_username;
            $response['done_by'] = $employee_username; 
            
            $query_parts[] = "ce_date = ?";
            $types .= "s";
            $params[] = $current_datetime; 
            $response['ce_date'] = $current_datetime;
        }
        
        // Check Flag Update Logic
        if ($check_field && $set_check_flag > 0) {
            $query_parts[] = "{$check_field} = ?";
            $types .= "i";
            $params[] = $set_check_flag;
        }
        
        // Status Update Logic
        if ($status_update_needed || $is_status_update) {
            $status_to_set = $is_status_update ? $new_value : 'In Progress';
            
            if ($update_field !== 'status' || $status_update_needed) {
                $query_parts[] = "status = ?";
                $types .= "s";
                $params[] = $status_to_set;
            }
        }
        
        // Final Query Construction and Execution
        if (empty($query_parts)) {
            throw new Exception("No fields to update.");
        }
        
        $query = "UPDATE ce SET " . implode(', ', $query_parts) . " WHERE id = ?";
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
        $query = "SELECT id, job_id, date, client, issue, articles, eq, images, tables_count, 
                         process, word_count, ref, tat, status, done_by, ce_date, 
                         eq_check, images_check, tables_count_check, ref_check 
                  FROM ce 
                  WHERE status != 'Completed' AND job_id = ? 
                  ORDER BY id DESC";
        
        $stmt = $mysqli->prepare($query);
        $stmt->bind_param("i", $job_id);
        $stmt->execute();
        $result = $stmt->get_result();
    } else {
        $query = "SELECT id, job_id, date, client, issue, articles, eq, images, tables_count, 
                         process, word_count, ref, tat, status, done_by, ce_date, 
                         eq_check, images_check, tables_count_check, ref_check 
                  FROM ce 
                  WHERE status != 'Completed' 
                  ORDER BY id DESC";
        
        $result = $mysqli->query($query);
    }
    
    $data = [];

    if ($result) {
        while ($row = $result->fetch_assoc()) {
            foreach ($row as $key => $val) { $row[$key] = $val ?? ''; }
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
    <title>CE Table Management - Job ID: <?php echo $job_id > 0 ? $job_id : 'All Jobs'; ?></title>
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
        /* Cell coloring based on flag value */
        .red-bg { background-color: #f8d7da !important; color: #721c24; }      
        .yellow-bg { background-color: #fffae0 !important; color: #664d03; }  
        .green-bg { background-color: #d4edda !important; color: #155724; }  
        /* Style for cells containing the new assignment button */
        td.cell-container { padding-right: 5px !important; }
        .cell-content-wrapper { position:relative; width:100%; height:100%; padding-right:35px; }
    </style>
</head>
<body class="bg-light">
    <div class="container-fluid mt-4">
        <div class="card shadow">
            <div class="card-header bg-primary text-white d-flex justify-content-between align-items-center">
                <h3>CE Records <?php echo $job_id > 0 ? "- Job ID: $job_id" : ''; ?></h3>
                <?php if($job_id > 0): ?>
                    <a href="assigned_jobs.php" class="btn btn-light btn-sm">Assigned Jobs</a>
                <?php else: ?>
                    <div class="input-group" style="width: 300px;">
                        <input type="number" id="jobIdFilter" class="form-control" placeholder="Enter Job ID" value="<?php echo $job_id > 0 ? $job_id : ''; ?>">
                        <button class="btn btn-light" onclick="filterByJobId()">Filter</button>
                    </div>
                <?php endif; ?>
            </div>
            <div class="card-body">
                <table id="ceTable" class="table table-hover table-bordered w-100">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Job ID</th>
                            <th>Date</th>
                            <th>Client</th>
                            <th>Issue</th>
                            <th>Articles</th>
                            <th>EQ</th>
                            <th>Images</th>
                            <th>Tables</th>
                            <th>Process</th>
                            <th>Word Count</th>
                            <th>Ref</th>
                            <th>TAT</th>
                            <th>Status</th>
                            <th>CE By</th>
                            <th>CE Date</th>
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
            window.location.href = 'ce.php?job_id=' + jobId;
        } else {
            window.location.href = 'ce.php';
        }
    }

    $(document).ready(function(){
        var showEditButton = <?php echo $showEditButtonJson; ?>;
        var userProcessIds = <?php echo $processIdsJson; ?>;

        var table = $('#ceTable').DataTable({
            ajax: '?fetch=data<?php echo $job_id > 0 ? '&job_id=' . $job_id : ''; ?>',
            columns: [
                {data: 'id'},
                {data: 'job_id'},
                {data: 'date'},
                {data: 'client'},
                {data: 'issue'},
                {data: 'articles'},
                {data: 'eq'},
                {data: 'images'},
                {data: 'tables_count'},
                {data: 'process'},
                {data: 'word_count'},
                {data: 'ref'},
                {data: 'tat'},
                {data: 'status'},
                {data: 'done_by'},
                {data: 'ce_date'}
            ],
            rowCallback: function(row, data){
                var isCompleted = (data.status === 'Completed');
                
                // 1. Row Status Coloring
                $(row).removeClass('status-need status-progress status-completed text-dark text-white');
                
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

                // 2. Cell Coloring and Editable Logic based on _check flags (0, 1, 2)
                var quantityAndRefCols = [
                    // Col Index : DB Field : Check Field : Required Process ID
                    { index: 6, field: 'eq', check_field: 'eq_check', required_process: '8' },
                    { index: 7, field: 'images', check_field: 'images_check', required_process: '10' },
                    { index: 8, field: 'tables_count', check_field: 'tables_count_check', required_process: '9' },
                    { index: 11, field: 'ref', check_field: 'ref_check', required_process: '11' }
                ];
                
                quantityAndRefCols.forEach(function(col) {
                    var cell = $('td', row).eq(col.index);
                    var check_value = parseInt(data[col.check_field]) || 0; 
                    
                    // Reset cell content and classes
                    var originalData = data[col.field];
                    cell.empty().text(originalData); 
                    cell.removeClass('editable red-bg green-bg yellow-bg cell-container');
                    cell.css('padding-right', ''); 
                    
                    var isAuthorizedForProcess = userProcessIds.includes(col.required_process);

                    if (!isCompleted && showEditButton) {
                        
                        if (check_value === 0) {
                            // FLAG 0: RED CELL 
                            cell.addClass('red-bg'); 
                            cell.attr('data-id', data.id);

                            if (isAuthorizedForProcess) {
                                // Only show button if authorized for this specific process
                                cell.addClass('editable cell-container');
                                cell.css('padding-right', '5px'); 
                                
                                var btnHtml = '<button class="btn btn-sm btn-primary assign-cell-btn" data-id="'+data.id+'" data-field="'+col.field+'" title="Assign this item to me" style="position:absolute; top:2px; right:2px; padding: 2px 5px;"><i class="bi bi-person-fill-add"></i></button>';
                                
                                // Wrap cell content to make space for the button
                                cell.empty().append('<div class="cell-content-wrapper" style="position:relative; width:100%; height:100%; padding-right:35px;">' + originalData + btnHtml + '</div>');
                            }
                        } else if (check_value === 1) { 
                            // FLAG 1: YELLOW CELL (Assigned, Editable, NO BUTTON)
                            cell.empty().text(originalData);
                            cell.addClass('yellow-bg');
                            // Cell is only editable if the user has the required process
                            if (isAuthorizedForProcess) {
                                cell.addClass('editable'); 
                            }
                            cell.attr('data-id', data.id);
                            
                        } else if (check_value === 2) { 
                            // FLAG 2: GREEN CELL (Completed, Not editable)
                            cell.addClass('green-bg'); 
                        }
                    } else if (check_value === 2 || isCompleted) {
                        cell.addClass('green-bg'); 
                    }
                });
                
                // 3. Word Count (10) and Status (13) columns
                var isWordCountAuthorized = userProcessIds.includes('7');
                if (!isCompleted && showEditButton && isWordCountAuthorized) {
                    $('td', row).eq(10).addClass('editable').attr('data-id', data.id); 
                }
                
                // Status (Column 13) - Editable if general edit is enabled and not completed
                if (!isCompleted && showEditButton) {
                    $('td', row).eq(13).addClass('editable').attr('data-id', data.id); 
                }
            }
        });

        // --------------------------------------------------------------------------------
        // 1. YELLOW CELL HANDLER (Flag 1 -> Flag 2: The SECOND Click / Final Entry)
        // --------------------------------------------------------------------------------
        $('#ceTable').on('click', 'td.yellow-bg.editable', function(){
            if($(this).find('input, select').length > 0) return;

            var cell = table.cell(this);
            var oldValue = cell.data();
            var id = $(this).data('id');
            var colIndex = cell.index().column;

            var fieldMap = {6:'eq', 7:'images', 8:'tables_count', 11:'ref'};
            var field = fieldMap[colIndex];
            if (!field) return; 
            
            // Extract only the number if the old value is the 'Username / In Progress' string
            var numberMatch = String(oldValue).match(/(\d+)/);
            var initialValue = numberMatch ? numberMatch[0] : '';

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
                        update_ce_date: 1,  
                        set_check_flag: 2   
                    };
                    
                    $.post('', postData, function(res){
                        if(res.success){
                            cell.data(res.new_data).draw(); 
                            table.row(cell.index().row).invalidate().draw();
                        } else {
                            alert(res.error || 'Update failed on second click');
                            table.row(cell.index().row).invalidate().draw();
                        }
                    }, 'json').fail(function() {
                        alert('Server communication failed for second click update.');
                        table.row(cell.index().row).invalidate().draw();
                    });
                }
            });
        });

        // --------------------------------------------------------------------------------
        // 2. RED CELL HANDLER (Flag 0 -> Flag 2: The FIRST Click - Manual Edit)
        // --------------------------------------------------------------------------------
        $('#ceTable').on('click', 'td.red-bg.editable', function(){
            // Prevent if the user is authorized for the button (manual edit should only be possible if the button isn't there)
            if($(this).find('.assign-cell-btn').length > 0) return; 
            
            if($(this).find('input, select').length > 0) return;

            var cell = table.cell(this);
            var oldValue = cell.data();
            var id = $(this).data('id');
            var colIndex = cell.index().column;

            var fieldMap = {6: 'eq', 7: 'images', 8: 'tables_count', 11: 'ref'};
            var field = fieldMap[colIndex];
            if (!field) return; 
            
            $(this).html('<input type="text" class="form-control form-control-sm" value="'+oldValue+'">');
            $(this).find('input').focus().select();

            $(this).find('input').on('blur keyup', function(e){
                if(e.type === 'blur' || e.key === 'Enter'){
                    var newValue = $(this).val();
                    
                    if(String(newValue).trim() === String(oldValue).trim()){
                        table.row(cell.index().row).invalidate().draw();
                        return;
                    }

                    newValue = String(newValue).trim(); 
                    var flag_to_set = 2; 

                    var postData = {
                        id: id, 
                        [field]: newValue, 
                        set_check_flag: flag_to_set,
                        update_ce_date: 1 
                    };
                    
                    $.post('', postData, function(res){
                        if(res.success){
                            cell.data(newValue).draw();
                            table.row(cell.index().row).invalidate().draw(); 
                        } else { 
                            alert(res.error || 'Update failed'); 
                            table.row(cell.index().row).invalidate().draw();
                        }
                    }, 'json').fail(function() {
                        alert('Server communication failed for first click update.');
                        table.row(cell.index().row).invalidate().draw();
                    });
                }
            });
        });

        // --------------------------------------------------------------------------------
        // 3. GENERIC SINGLE CLICK HANDLER (Word Count / Status)
        // --------------------------------------------------------------------------------
        $('#ceTable').on('click', 'td.editable:not(.red-bg):not(.yellow-bg)', function(){
            // Prevent multiple editors in one cell
            if($(this).find('input, select').length > 0) return;

            var cell = table.cell(this);
            var oldValue = cell.data();
            var id = $(this).data('id');
            var colIndex = cell.index().column;

            var fieldMap = {10: 'word_count', 13: 'status'};
            var field = fieldMap[colIndex];
            if (!field) return; 

            // For Status column (13), use a dropdown
            if (field === 'status') {
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

            } 
            // For Word Count column (10), use a text input
            else if (field === 'word_count') {
                cell.data('<input type="text" class="form-control form-control-sm" value="'+oldValue+'">');
                var inputElement = $(this).find('input');
                inputElement.focus().select();

                inputElement.on('blur keyup', function(e){
                    if(e.type === 'blur' || e.key === 'Enter'){
                        var newValue = $(this).val();
                        
                        if(String(newValue).trim() === String(oldValue).trim()){
                            table.row(cell.index().row).invalidate().draw();
                            return;
                        }

                        newValue = String(newValue).trim();
                        
                        var postData = {
                            id: id, 
                            word_count: newValue
                        };
                        
                        // Update Done By and CE Date (tracking info) on Word Count update
                        $.post('', postData, function(res){
                            if(res.success){
                                cell.data(newValue).draw();
                                
                                if (res.done_by) { table.cell(cell.index().row, 14).data(res.done_by).draw(); }
                                if (res.ce_date) { table.cell(cell.index().row, 15).data(res.ce_date).draw(); }

                                table.row(cell.index().row).invalidate().draw();
                            } else {
                                alert(res.error || 'Word count update failed.');
                                table.row(cell.index().row).invalidate().draw();
                            }
                        }, 'json').fail(function() {
                            alert('Server communication failed for word count update.');
                            table.row(cell.index().row).invalidate().draw();
                        });
                    }
                });
            }
        });

        // --------------------------------------------------------------------------------
        // 4. CELL-LEVEL ASSIGN TO ME HANDLER (Flag 0 -> Flag 1) 
        // --------------------------------------------------------------------------------
        $('#ceTable').on('click', '.assign-cell-btn', function(e) {
            e.stopPropagation(); 

            var button = $(this);
            var cellElement = button.closest('td');
            var cell = table.cell(cellElement);
            var id = button.data('id');
            var field = button.data('field'); 
            var cell_data = cell.data(); 

            button.prop('disabled', true).html('<i class="bi bi-arrow-clockwise spin"></i>');

            var postData = {
                id: id, 
                [field]: cell_data, 
                set_check_flag: 1 
            };
            
            $.post('', postData, function(res){
                if(res.success){
                    if (res.done_by) { 
                        table.cell(cell.index().row, 14).data(res.done_by).draw();
                    }
                    if (res.ce_date) { 
                        table.cell(cell.index().row, 15).data(res.ce_date).draw();
                    }
                    if (res.new_data) {
                        cell.data(res.new_data); 
                    }
                    
                    // Update status cell if status was changed to 'In Progress'
                    if(res.status_updated){
                        table.cell(cell.index().row, 13).data('In Progress');
                    }
                    // Force full reload to ensure the UI gets the definitive check flag=1 data for all cells.
                    table.ajax.reload(null, false);
                    
                } else {
                    alert(res.error || 'Cell assignment failed.');
                    table.ajax.reload(null, false); 
                }
            }, 'json').fail(function() {
                alert('Server communication failed for cell assignment.');
                table.ajax.reload(null, false); 
            }).always(function() {
                button.prop('disabled', false).html('<i class="bi bi-person-fill-add"></i>');
            });
        });
    });
    </script>
</body>
</html>