<br><br><br>
<?php
session_start();
require 'db.php'; 

// Fetch data for the table view - FIXED QUERY WITH ALL CE DATA
$query = "SELECT 
            le.id, 
            le.job_id, 
            le.date, 
            le.client, 
            le.issue, 
            le.articles, 
            le.word_count,     -- LE Word Count (Editable)
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
            -- ADDED: Process name from job_master through jobs table
            jm.job_name AS process
          FROM le
          JOIN ce ON le.job_id = ce.job_id
          JOIN jobs j ON le.job_id = j.job_id
          JOIN job_master jm ON j.process_id = jm.job_id
          -- Filter: CE is done, and LE is not completed (i.e., ready for LE work)
          WHERE ce.status='Completed' AND le.status!='Completed'
          ORDER BY le.status, le.id DESC";

$result = $mysqli->query($query);

$data = [];
$current_date = new DateTime();

if ($result) {
    while ($row = $result->fetch_assoc()) {
        // Debug: Check what data we're getting
        // echo "<pre>"; print_r($row); echo "</pre>";
        
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
    $result->free();
} else {
    // Debug: Show SQL error if any
    die("Query failed: " . $mysqli->error);
}

// User authentication check
$uid = $_SESSION['uid'] ?? 0;
$showEditButton = false;
$process_ids = [];

if($uid){
    $stmt = $mysqli->prepare("SELECT process, user_role_id FROM employee_master WHERE employee_id=?");
    
    if ($stmt) {
        $stmt->bind_param("i", $uid);
        $stmt->execute();
        $stmt->bind_result($process, $user_role_id);
        
        if($stmt->fetch()){
            $process_ids = array_filter(array_map('trim', explode(',', $process)));

            if($user_role_id == 4 && in_array('6', $process_ids)){ 
                $showEditButton = true;
            }
        }
        $stmt->close();
    }
}

$showEditButtonJson = json_encode($showEditButton);
$tableDataJson = json_encode(['data' => $data], JSON_NUMERIC_CHECK);
?>
<?php require 'header.php';?>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <meta charset="UTF-8">
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/jquery.dataTables.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
    <style>
    @keyframes spin {
        0% { transform: rotate(0deg); }
        100% { transform: rotate(360deg); }
    }
    .spin {
        animation: spin 1s linear infinite;
    }
    td.editable { cursor: pointer; } 
    .status-need { background-color: #dc3545 !important; color: #fff !important; }    
    .status-progress { background-color: #ffc107 !important; color: #000 !important; } 
    .status-completed { background-color: #28a745 !important; color: #fff !important; } 
    </style>
    <h3>LE Records</h3>
    
    <!-- Debug information -->
    <!--div class="alert alert-info d-none" id="debugInfo">
        <strong>Debug Info:</strong>
        <div id="debugContent"></div>
    </div-->
    
    <table id="leTable" class="table table-bordered table-striped">
        <thead>
            <tr>
                <th>ID</th>              <th>Job ID</th>          <th>Date</th>            <th>Client</th>          <th>Issue</th>           <th>Articles</th>        
                <th>EQ</th>              <th>Images</th>          <th>Tables</th>          <th>Process</th>         
                <th>CE Word Count</th>   <th>CE Ref</th>          <th>LE Word Count</th>   <th>Days Left</th>       
                <th>Status</th>          <th>LE By</th>           <th>LE Date</th>         <th>Actions</th>
            </tr>
        </thead>
        <tbody>
            <!-- DataTables will populate this automatically -->
        </tbody>
    </table>
</div>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
$(document).ready(function(){
    var showEditButton = <?php echo $showEditButtonJson; ?>;
    var tableData = <?php echo $tableDataJson; ?>;

    // Debug: Show what data we have
    console.log('Table Data:', tableData);
    if (tableData.data && tableData.data.length > 0) {
        console.log('First row sample:', tableData.data[0]);
        $('#debugContent').html('Data loaded: ' + tableData.data.length + ' records. First row: ' + JSON.stringify(tableData.data[0]));
        $('#debugInfo').removeClass('d-none');
    }

    var table = $('#leTable').DataTable({
        data: tableData.data,
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
            { // 17 - Actions
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
            }
        ],
        columnDefs: [
            {
                targets: [6, 7, 8, 10, 11], // CE Data columns
                render: function(data, type, row) {
                    // Handle null/undefined values for CE data
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

            // Debug: Log CE data for this row
            console.log('Row CE Data:', {
                job_id: data.job_id,
                ce_images: data.ce_images,
                ce_tables_count: data.ce_tables_count,
                ce_word_count: data.ce_word_count
            });

            // Editable Logic
            if (!isCompleted && showEditButton) {
                // LE Word Count (Index 12)
                $('td', row).eq(12).addClass('editable').attr('data-id', data.id); 
                
                // Status (Index 14)
                $('td', row).eq(14).addClass('editable').attr('data-id', data.id); 
            } else {
                $('td', row).eq(12).removeClass('editable'); 
                $('td', row).eq(14).removeClass('editable'); 
            }
        },
        createdRow: function(row, data, dataIndex) {
            // Additional debug: Add data attributes to see CE values
            $(row).attr('data-ce-images', data.ce_images || '0');
            $(row).attr('data-ce-tables', data.ce_tables_count || '0');
            $(row).attr('data-ce-wordcount', data.ce_word_count || '0');
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
            var options = '<option value="In Progress">In Progress</option>';
            if(oldValue !== 'In Progress' && oldValue !== 'Completed'){
                 options = '<option value="Need to Assign">Need to Assign</option>' + options;
            }
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
                
                // Logic for triggering date/tat update
                if (field === 'word_count' || (field === 'status' && newValue === 'Completed')) {
                    postData.update_le_date = 1; 
                }

                $.post('le_update.php', postData, function(res){ 
                    if(res.success){
                        cell.data(newValue).draw();
                        
                        // Update Status column if Word Count was the primary update
                        if (field === 'word_count' && res.status_updated) {
                             table.cell(cell.index().row, 14).data('Completed').draw(); 
                        }
                        
                        // Update LE By and LE Date
                        if (res.done_by) { table.cell(cell.index().row, 15).data(res.done_by).draw(); }
                        if (res.le_date) { table.cell(cell.index().row, 16).data(res.le_date).draw(); } 
                        
                        table.row(cell.index().row).invalidate().draw(); 
                    } else {
                        alert(res.error || 'Update failed');
                        table.row(cell.index().row).invalidate().draw();
                    }
                }, 'json').fail(function() {
                    alert('Server communication failed for single click update.');
                    table.row(cell.index().row).invalidate().draw();
                });
            }
        });
    });

    // --------------------------------------------------------------------------------
    // 2. MANAGE BUTTON HANDLER (Final Completion Logic)
    // --------------------------------------------------------------------------------
    $('#leTable').on('click', '.manage-record', function() {
        var button = $(this);
        var id = button.data('id');
        var jobId = button.data('jobid');

        var rowData = table.row(button.closest('tr')).data();

        if (!rowData.word_count || parseInt(rowData.word_count) <= 0) {
            alert('Please enter the Word Count before marking the record as Completed.');
            return;
        }
        
        if (!confirm('Are you sure you want to mark Job ID ' + jobId + ' as Completed? This action is final.')) {
            return;
        }

        button.prop('disabled', true).html('<i class="bi bi-arrow-clockwise spin"></i>');

        $.post('le_complete.php', {id: id, job_id: jobId}, function(res) { 
            if (res.success) {
                alert('Job ID ' + jobId + ' successfully completed and removed from the list!');
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