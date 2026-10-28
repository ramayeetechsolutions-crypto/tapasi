<?php
session_start();
require 'db.php'; 
require 'header.php';

//ini_set('display_errors', 1);
//ini_set('display_startup_errors', 1);
//error_reporting(E_ALL);

// Fetch data directly for DataTables
$query = "SELECT 
            le.id, 
            le.job_id, 
            le.date, 
            le.client, 
            le.issue, 
            le.articles, 
            le.eq, 
            le.images, 
            le.tables_count, 
            le.process, 
            le.word_count, 
            le.ref, 
            le.tat, 
            le.status, 
            le.done_by, 
            le.le_date,        
            le.due_date,       
            le.created_at,     
            le.last_updated,   
            le.eq_check,
            le.tables_count_check, 
            le.images_check,
            le.ref_check
          FROM le, ce where le.job_id=ce.job_id and ce.status='Completed' and le.status!='Completed'
        ORDER BY le.status";

$result = $mysqli->query($query);

$data = [];
if ($result) {
    while ($row = $result->fetch_assoc()) {
        foreach ($row as $key => $value) {
            if ($value === null) {
                $row[$key] = '';
            }
        }
        $data[] = $row;
    }
    $result->free();
}

// User authentication
$uid = $_SESSION['uid'] ?? 0;
$showEditButton = false;
$process_ids = [];

if($uid){
    // Fetch user role and processes
    $stmt = $mysqli->prepare("SELECT process, user_role_id FROM employee_master WHERE employee_id=?");
    
    if ($stmt) {
        $stmt->bind_param("i", $uid);
        $stmt->execute();
        $stmt->bind_result($process, $user_role_id);
        
        if($stmt->fetch()){
            $process_ids = array_filter(array_map('trim', explode(',', $process)));

            // Authorize user (example: role 4 and process 6)
            if($user_role_id == 4 && in_array('6', $process_ids)){ 
                $showEditButton = true;
            }
        }
        $stmt->close();
    }
}

$showEditButtonJson = json_encode($showEditButton);
$tableDataJson = json_encode(['data' => $data]);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <meta charset="UTF-8">
    <title>LE Table Inline Edit</title>
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/jquery.dataTables.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
    <style>
    /* CSS for Spinner Animation */
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

    /* Cell coloring override based on flag value */
    .red-bg { background-color: #f8d7da !important; color: #721c24; }     
    .yellow-bg { background-color: #fffae0 !important; color: #664d03; }  
    .green-bg { background-color: #d4edda !important; color: #155724; }  

    /* Style for cells containing the new assignment button */
    td.cell-container { padding-right: 5px !important; }
    .cell-content-wrapper { position:relative; width:100%; height:100%; padding-right:35px; }
    </style>
</head>
<body>
<div class="container mt-3">
    <h3>LE Records</h3>
    <table id="leTable" class="table table-bordered table-striped">
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
                <th>LE By</th>
                <th>LE Date</th>
                <th>Actions</th>
            </tr>
        </thead>
    </table>
</div>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
$(document).ready(function(){
    var showEditButton = <?php echo $showEditButtonJson; ?>;
    var tableData = <?php echo $tableDataJson; ?>;

    var table = $('#leTable').DataTable({
        data: tableData.data,
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
            {data: 'le_date'},
            {data: null} // Placeholder for Action column
        ],
        columnDefs: [
            {
                targets: 16, // Action Column
                data: null,
                render: function(data, type, row) {
                    if (!showEditButton) {
                        return '';
                    }
                    if (row.status === 'In Progress') {
                        return '<button class="btn btn-sm btn-warning manage-record" data-id="'+row.id+'" data-jobid="'+row.job_id+'" title="Complete Record"><i class="bi bi-gear"></i> Manage</button>';
                    }
                    return '';
                },
                orderable: false,
                searchable: false
            },
            
            // --- CRITICAL: DISABLE CLIENT-SIDE SORTING FOR STATUS COLUMN ---
            {
                targets: 13, // Status Column (0-indexed)
                orderable: false // This prevents DataTables from overriding the SQL's custom order
            }  
            // ---------------------------------------------------------------
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
                { index: 6, field: 'eq', check_field: 'eq_check' },
                { index: 7, field: 'images', check_field: 'images_check' }, 
                { index: 8, field: 'tables_count', check_field: 'tables_count_check' },
                { index: 11, field: 'ref', check_field: 'ref_check' } 
            ];

            quantityAndRefCols.forEach(function(col) {
                var cell = $('td', row).eq(col.index);
                var check_value = parseInt(data[col.check_field]) || 0; 
                
                // --- Reset cell content and classes ---
                var originalData = data[col.field];
                cell.empty().text(originalData); 
                cell.removeClass('editable red-bg green-bg yellow-bg cell-container');
                cell.css('padding-right', ''); 
                // --------------------------------------
                
                if (!isCompleted && showEditButton) {
                    
                    if (check_value === 0) {
                        // FLAG 0: RED CELL with ASSIGN BUTTON
                        cell.addClass('red-bg editable cell-container'); 
                        cell.attr('data-id', data.id);
                        cell.css('padding-right', '5px'); 

                        var btnHtml = '<button class="btn btn-sm btn-primary assign-cell-btn" data-id="'+data.id+'" data-field="'+col.field+'" title="Assign this item to me" style="position:absolute; top:2px; right:2px; padding: 2px 5px;"><i class="bi bi-person-fill-add"></i></button>';
                        
                        cell.empty().append('<div class="cell-content-wrapper" style="position:relative; width:100%; height:100%; padding-right:35px;">' + originalData + btnHtml + '</div>');
                        
                    } else if (check_value === 1) { 
                        // FLAG 1: YELLOW CELL (Assigned, Editable, NO BUTTON)
                        cell.empty().text(originalData);
                        cell.addClass('yellow-bg editable'); 
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
            if (!isCompleted && showEditButton) {
                $('td', row).eq(10).addClass('editable').attr('data-id', data.id); 
                $('td', row).eq(13).addClass('editable').attr('data-id', data.id); 
            }
        }
    });

// --------------------------------------------------------------------------------
// 1. YELLOW CELL HANDLER (Flag 1 -> Flag 2: The SECOND Click / Final Entry)
//    Saves the composite string: [user]/[date]/[number]
// --------------------------------------------------------------------------------
$('#leTable').on('click', 'td.yellow-bg.editable', function(){
    if($(this).find('input, select').length > 0) return;

    var cell = table.cell(this);
    var oldValue = cell.data();
    var id = $(this).data('id');
    var colIndex = cell.index().column;

    var fieldMap = {6:'eq', 7:'images', 8:'tables_count', 11:'ref'};
    var field = fieldMap[colIndex];
    if (!field) return; 
    
    // Extract only the number from the 'Username / In Progress' string
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
                update_le_date: 1,  
                set_check_flag: 2   
            };

            $.post('le_update.php', postData, function(res){ 
                if(res.success){
                    cell.data(res.new_data).draw(); 
                    
                    if (res.done_by) { table.cell(cell.index().row, 14).data(res.done_by).draw(); }
                    if (res.le_date) { table.cell(cell.index().row, 15).data(res.le_date).draw(); } 
                    
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
$('#leTable').on('click', 'td.red-bg.editable', function(){
    if($(this).find('input, select, button').length > 0) return;

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
                update_le_date: 1 
            };

            $.post('le_update.php', postData, function(res){ 
                if(res.success){
                    cell.data(newValue).draw();
                    if (res.done_by) { table.cell(cell.index().row, 14).data(res.done_by).draw(); }
                    if (res.le_date) { table.cell(cell.index().row, 15).data(res.le_date).draw(); } 

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
$('#leTable').on('click', 'td.editable:not(.red-bg):not(.yellow-bg)', function(){
    if($(this).find('input, select').length > 0) return;

    var cell = table.cell(this);
    var oldValue = cell.data();
    var id = $(this).data('id');
    var colIndex = cell.index().column;

    var fieldMap = {10:'word_count', 13:'status'};
    var field = fieldMap[colIndex];
    
    if (!field) return; 

    // Create the appropriate input field
    if(field === 'status'){
        var options = '<option value="'+oldValue+'">'+oldValue+'</option>';
        if(oldValue !== 'Completed'){
            options += '<option value="Completed">Completed</option>';
        }
        
        $(this).html('<select class="form-select form-select-sm">'+options+'</select>');
        $(this).find('select').val(oldValue).focus();
    } else {
        // Must be Word Count (index 10)
        $(this).html('<input type="text" class="form-control form-control-sm" value="'+oldValue+'">');
        $(this).find('input').focus().select();
    }

    $(this).find('input, select').on('blur keyup', function(e){
        if(e.type === 'blur' || e.key === 'Enter'){
            var newValue = $(this).val();
            if(String(newValue).trim() !== String(oldValue).trim()){
                
                var postData = {id: id, [field]: newValue}; 
                
                if (field === 'word_count') {
                    // Force status update to 'Completed' and update date/TAT
                    postData.status = 'Completed';
                    postData.update_le_date = 1; 
                } else if (field === 'status' && (newValue === 'Completed' || newValue === 'In Progress')) {
                    postData.update_le_date = 1; 
                }

                $.post('le_update.php', postData, function(res){ 
                    if(res.success){
                        cell.data(newValue).draw();
                        
                        // Update the Status and TAT columns directly on success
                        if (field === 'word_count' && res.status_updated) {
                            table.cell(cell.index().row, 13).data('Completed').draw(); // Status
                        }
                        if (res.tat) { 
                             table.cell(cell.index().row, 12).data(res.tat).draw(); // TAT Column
                        }
                        if (res.done_by) { table.cell(cell.index().row, 14).data(res.done_by).draw(); }
                        if (res.le_date) { table.cell(cell.index().row, 15).data(res.le_date).draw(); } 
                        
                        table.row(cell.index().row).invalidate().draw(); 
                    } else {
                        alert(res.error || 'Update failed');
                        table.row(cell.index().row).invalidate().draw();
                    }
                }, 'json').fail(function() {
                     alert('Server communication failed for single click update.');
                     table.row(cell.index().row).invalidate().draw();
                });
            } else {
                table.row(cell.index().row).invalidate().draw();
            }
        }
    });
});

// --------------------------------------------------------------------------------
// 5. CELL-LEVEL ASSIGN TO ME HANDLER (Flag 0 -> Flag 1) 
// --------------------------------------------------------------------------------
$('#leTable').on('click', '.assign-cell-btn', function(e) {
    e.stopPropagation(); 

    var button = $(this);
    var row = button.closest('tr');
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

    $.post('le_update.php', postData, function(res){ 
        if(res.success){
            if (res.done_by) { 
                table.cell(cell.index().row, 14).data(res.done_by).draw();
            }
            if (res.new_data) {
                cell.data(res.new_data); 
            }
            
            if(res.status_updated){
                 table.cell(cell.index().row, 13).data('In Progress');
            }
            table.row(cell.index().row).invalidate().draw();
            
        } else {
            alert(res.error || 'Cell assignment failed.');
            table.row(cell.index().row).invalidate().draw();
        }
    }, 'json').fail(function() {
        alert('Server communication failed for cell assignment.');
        table.row(cell.index().row).invalidate().draw();
    }).always(function() {
         button.prop('disabled', false).html('<i class="bi bi-person-fill-add"></i>');
    });
});


// --------------------------------------------------------------------------------
// 6. MANAGE ICON HANDLER (Final Completion Logic)
// --------------------------------------------------------------------------------
$('#leTable').on('click', '.manage-record', function() {
    var button = $(this);
    var id = button.data('id');
    var jobId = button.data('jobid');

    var rowData = table.row(button.closest('tr')).data();

    if (rowData && rowData.status === 'Completed') {
        alert('This record is already completed.');
        return;
    }

    if (!confirm('Are you sure you want to mark Job ID ' + jobId + ' as Completed?')) {
        return;
    }

    button.prop('disabled', true).html('<i class="bi bi-arrow-clockwise spin"></i>');

    $.post('le_complete.php', {id: id, job_id: jobId}, function(res) { 
        if (res.success) {
            alert('Job ID ' + jobId + ' successfully completed!');
            table.ajax.reload(null, false); 
        } else {
            alert('Completion failed: ' + (res.error || 'Unknown error'));
        }
    }, 'json').fail(function() {
        alert('Server communication failed. Check le_complete.php.');
    }).always(function() {
         button.prop('disabled', false).html('<i class="bi bi-gear"></i> Manage');
    });
});
    
});
</script>

</body>
</html>