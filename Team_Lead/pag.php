<br><br><br>
<?php
// pag.php
session_start();
require 'db.php'; 

$uid = $_SESSION['uid'] ?? 0;
$employee_name = $_SESSION['employee_name'] ?? 'Unknown User'; // Use employee_name for consistency
$showEditButton = false;
$process_ids = [];

if($uid){
    // Fetch user role and processes
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
            $_SESSION['employee_name'] = $fetched_employee_name; // Ensure session is set
            $employee_name = $fetched_employee_name;
        }
        $stmt->close();
    }
}

$showEditButtonJson = json_encode($showEditButton);
$userProcessIdsJson = json_encode($process_ids); 
$employeeNameJson = json_encode($employee_name);
?>
<?php require 'header.php';?>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
<meta charset="UTF-8">
<link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/jquery.dataTables.min.css">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
<style>
/* Standard CSS for coloring and editing */
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
/* IMPORTANT: The padding-right is critical for the button spacing */
.cell-content-wrapper { position:relative; width:100%; height:100%; padding-right:35px; } 
tr.status-need td.red-bg { background-color: #f8d7da !important; color: #721c24 !important; } 
</style>
    <h3>Paginator Records</h3>
    <table id="pagTable" class="table table-bordered table-striped">
        <thead>
            <tr>
                <th>Job ID</th>      <th>Date</th>        <th>Client</th>      <th>Issue</th>       <th>Articles</th>    <th>Pages (PAG)</th> <th>Images (PAG)</th><th>Tables (PAG)</th><th>Status</th>      <th>PAG By</th>      <th>PAG Date</th>    <th></th>            </tr>
        </thead>
    </table>
</div>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
$(document).ready(function(){
    var showEditButton = <?php echo $showEditButtonJson; ?>;
    var userProcessIds = <?php echo $userProcessIdsJson; ?>;
    var employeeName = <?php echo $employeeNameJson; ?>;

    var table = $('#pagTable').DataTable({
        ajax: 'pag_fetch.php', 
        columns: [
            {data: 'job_id'},          // 0
             {
        data: 'date',          // 1 - Date with MM/DD/YYYY format
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
    },            // 1
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

                // --- START CUSTOM LOGIC FOR BUTTON REMOVAL ---
                var hasAssignButton = (col.index !== 5); // ONLY Pages (Col 5) does NOT get a button

                if (!isCompleted && showEditButton) {
                    
                    if (check_value === 0) {
                        // FLAG 0: RED CELL (Need to Assign/Update)
                        cell.addClass('red-bg'); 
                        cell.attr('data-id', data.job_id); // Sending job_id
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
                                // Pages (5) is editable directly, no button, no special padding/wrapper
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
            
            // 3. Status (Column 8)
            if (!isCompleted && showEditButton) {
                $('td', row).eq(8).addClass('editable').attr('data-id', data.job_id); 
            }
        },
        // IMPORTANT: Ensure the job_id is used for editing
        createdRow: function(row, data, dataIndex) {
            $(row).find('td').attr('data-id', data.job_id);
        }
    });

    // --------------------------------------------------------------------------------
    // 1. YELLOW CELL HANDLER (Flag 1 -> Flag 2: Final Entry) - Used by all columns
    // --------------------------------------------------------------------------------
    $('#pagTable').on('click', 'td.yellow-bg.editable', function(){
        if($(this).find('input, select').length > 0) return;

        var cell = table.cell(this);
        // Use the stored original numeric value, not the 'Name / In Progress' string
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

                var postData = {};
                postData.id = id; 
                postData[field] = newValue; // Send field name as key (e.g., pages: '100')
                postData.set_check_flag = 2;
                postData.update_pag_date = 1; // Explicitly set the completion format flag
                
                // POST to the dedicated PAG update file
                $.post('pag_update.php', postData, function(res){
                    if(res.success){
                        // Update tracking info display
                        table.cell(cell.index().row, 9).data(res.done_by).draw();
                        table.cell(cell.index().row, 10).data(res.pag_date).draw();
                        // DataTables auto-reloads the cell with the new formatted string (res.new_data)
                        table.cell(cell.index().row, cell.index().column).data(res.new_data).draw();
                        // Force full reload to change cell color from yellow to green
                        table.ajax.reload(null, false); 
                    } else {
                        alert(res.error || 'Update failed on final entry');
                        table.ajax.reload(null, false);
                    }
                }, 'json').fail(function(jqXHR, textStatus, errorThrown) {
                     alert('Server communication failed for final entry update. Check console for details.');
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

        // Only proceed if this is a PAG field (cols 5, 6, 7) AND does NOT have the button wrapper class
        if (!field || field !== 'pages') return; 

        // The logic for Pages (Col 5) is exactly the same as the yellow cell completion, but starts from RED.
        
        $(this).html('<input type="text" class="form-control form-control-sm" value="'+initialValue+'">');
        $(this).find('input').focus().select();

        $(this).find('input').on('blur keyup', function(e){
            if(e.type === 'blur' || e.key === 'Enter'){
                var newValue = $(this).val();
                
                if(String(newValue).trim() === String(initialValue).trim()){
                    table.row(cell.index().row).invalidate().draw(); 
                    return;
                }

                var postData = {};
                postData.id = id; 
                postData[field] = newValue;
                postData.set_check_flag = 2; // Directly to completed
                postData.update_pag_date = 1; 
                
                $.post('pag_update.php', postData, function(res){
                    if(res.success){
                        table.cell(cell.index().row, 9).data(res.done_by).draw();
                        table.cell(cell.index().row, 10).data(res.pag_date).draw();
                        if(res.status_updated){ table.cell(cell.index().row, 8).data('Completed').draw(); }
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
        var id = button.data('id'); // This is the job_id
        var field = button.data('field'); 
        
        button.prop('disabled', true).html('<i class="bi bi-arrow-clockwise spin"></i>');

        var postData = {};
        postData.id = id; 
        postData[field] = '0'; // Send the current count (0) as the value (required for update)
        postData.set_check_flag = 1; // Setting the flag to ASSIGNED
        
        // POST to the dedicated PAG update file
        $.post('pag_update.php', postData, function(res){
            if(res.success){
                // Update tracking info display directly
                table.cell(cell.index().row, 9).data(res.done_by).draw();
                table.cell(cell.index().row, 10).data(res.pag_date).draw();
                // Update status cell display
                if(res.status_updated){ table.cell(cell.index().row, 8).data('In Progress'); }
                
                // Force full reload to change cell color from red to yellow
                table.ajax.reload(null, false);
                
            } else {
                alert('PAG Cell assignment failed. Error: ' + (res.error || 'Check server logs.')); 
                table.ajax.reload(null, false); 
            }
        }, 'json').fail(function(jqXHR, textStatus, errorThrown) {
            var errorMsg = 'Server communication failed for single click update.';
            console.error("AJAX Error:", jqXHR.responseText);
            alert(errorMsg); 
            table.ajax.reload(null, false); 
        }).always(function() {
            button.prop('disabled', false).html('<i class="bi bi-person-fill-add"></i>');
        });
    });
    
    // ... (Status update handler omitted for brevity, but you should include it) ...
    // Note: If you copy the STATUS HANDLER from the previous response, ensure it targets column 8.
});
</script>
