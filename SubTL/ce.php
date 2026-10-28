<?php
session_start();
require 'db.php';
require 'header.php';

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
            // Get user's process IDs
            $process_ids = array_filter(array_map('trim', explode(',', $process)));

            // Authorize user if they have any of the core CE processes (7, 8, 9, 10, 11)
            $allowed_processes = ['7', '8', '9', '10', '11'];
            
            // Check if the user's process list intersects with the allowed list
            if (count(array_intersect($process_ids, $allowed_processes)) > 0) {
                $showEditButton = true;
            }
        }
        $stmt->close();
    }
}

$showEditButtonJson = json_encode($showEditButton);
$processIdsJson = json_encode($process_ids); 
?>

<div class="container-fluid">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h3><i class="bi bi-table me-2"></i> CE Records</h3>
        <div>
            <button id="refreshBtn" class="btn btn-outline-primary me-2">
                <i class="bi bi-arrow-clockwise"></i> Refresh
            </button>
            <a href="add_record.php" class="btn btn-primary">
                <i class="bi bi-plus-circle"></i> Add New Record
            </a>
        </div>
    </div>

    <div class="card">
        <div class="card-body">
            <div class="table-responsive">
                <table id="ceTable" class="table table-bordered table-striped table-hover">
                    <thead class="table-dark">
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
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <!-- Data will be loaded via AJAX -->
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<script>
$(document).ready(function(){
    var showEditButton = <?php echo $showEditButtonJson; ?>;
    var userProcessIds = <?php echo $processIdsJson; ?>;
    
    // Initialize DataTable
    var table = $('#ceTable').DataTable({
        ajax: {
            url: 'ce_fetch.php',
            dataSrc: 'data'
        },
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
            {data: 'ce_date'},
            {
                data: null,
                render: function(data, type, row) {
                    return createActionButtons(row);
                },
                orderable: false,
                searchable: false
            }
        ],
        order: [[0, 'desc']],
        pageLength: 25,
        responsive: true,
        rowCallback: function(row, data) {
            // Apply status-based row colors
            $(row).removeClass('table-danger table-warning table-success');
            
            switch(data.status) {
                case 'Need to Assign':
                    $(row).addClass('table-danger');
                    break;
                case 'In Progress':
                    $(row).addClass('table-warning');
                    break;
                case 'Completed':
                    $(row).addClass('table-success');
                    break;
            }
            
            // Highlight editable cells
            $('td', row).each(function(index) {
                $(this).removeClass('editable-cell');
                if (showEditButton && data.status !== 'Completed') {
                    // Make specific columns editable based on process permissions
                    if (index === 6 && userProcessIds.includes('8')) { // EQ
                        $(this).addClass('editable-cell');
                    } else if (index === 7 && userProcessIds.includes('10')) { // Images
                        $(this).addClass('editable-cell');
                    } else if (index === 8 && userProcessIds.includes('9')) { // Tables
                        $(this).addClass('editable-cell');
                    } else if (index === 10 && userProcessIds.includes('7')) { // Word Count
                        $(this).addClass('editable-cell');
                    } else if (index === 13) { // Status
                        $(this).addClass('editable-cell');
                    }
                }
            });
        }
    });

    // Function to create action buttons
    function createActionButtons(data) {
        var isCompleted = (data.status === 'Completed');
        var buttonsHtml = '';
        
        if (!isCompleted && showEditButton) {
            // Equation Button (Process 8)
            if (userProcessIds.includes('8')) {
                buttonsHtml += '<button class="btn btn-sm btn-outline-primary me-1 eq-btn" ' +
                               'data-id="' + data.id + '" ' +
                               'data-field="eq" ' +
                               'data-value="' + data.eq + '" ' +
                               'title="Update Equation Count">' +
                               '<i class="bi bi-superscript"></i> EQ</button>';
            }
            
            // Image Button (Process 10)
            if (userProcessIds.includes('10')) {
                buttonsHtml += '<button class="btn btn-sm btn-outline-info me-1 img-btn" ' +
                               'data-id="' + data.id + '" ' +
                               'data-field="images" ' +
                               'data-value="' + data.images + '" ' +
                               'title="Update Image Count">' +
                               '<i class="bi bi-image"></i> IMG</button>';
            }
            
            // Table Button (Process 9)
            if (userProcessIds.includes('9')) {
                buttonsHtml += '<button class="btn btn-sm btn-outline-warning me-1 table-btn" ' +
                               'data-id="' + data.id + '" ' +
                               'data-field="tables_count" ' +
                               'data-value="' + data.tables_count + '" ' +
                               'title="Update Table Count">' +
                               '<i class="bi bi-table"></i> TBL</button>';
            }
            
            // Word Count Button (Process 7)
            if (userProcessIds.includes('7')) {
                buttonsHtml += '<button class="btn btn-sm btn-outline-success me-1 wordcount-btn" ' +
                               'data-id="' + data.id + '" ' +
                               'data-field="word_count" ' +
                               'data-value="' + data.word_count + '" ' +
                               'title="Update Word Count">' +
                               '<i class="bi bi-file-text"></i> WC</button>';
            }
            
            // View/Edit Details Button
            buttonsHtml += '<button class="btn btn-sm btn-outline-secondary details-btn" ' +
                           'data-id="' + data.id + '" ' +
                           'title="View/Edit Details">' +
                           '<i class="bi bi-pencil-square"></i></button>';
        } else {
            buttonsHtml = '<span class="badge bg-secondary">Completed</span>';
        }
        
        return '<div class="btn-group btn-group-sm">' + buttonsHtml + '</div>';
    }

    // Refresh button
    $('#refreshBtn').click(function() {
        table.ajax.reload();
        showSuccessToast('Table refreshed successfully');
    });

    // Equation Button Handler
    $('#ceTable').on('click', '.eq-btn', function(e) {
        e.stopPropagation();
        openUpdateModal($(this).data('id'), 'eq', 'Equation', 'bi-superscript', $(this).data('value'));
    });

    // Image Button Handler
    $('#ceTable').on('click', '.img-btn', function(e) {
        e.stopPropagation();
        openUpdateModal($(this).data('id'), 'images', 'Image', 'bi-image', $(this).data('value'));
    });

    // Table Button Handler
    $('#ceTable').on('click', '.table-btn', function(e) {
        e.stopPropagation();
        openUpdateModal($(this).data('id'), 'tables_count', 'Table', 'bi-table', $(this).data('value'));
    });

    // Word Count Button Handler
    $('#ceTable').on('click', '.wordcount-btn', function(e) {
        e.stopPropagation();
        openUpdateModal($(this).data('id'), 'word_count', 'Word Count', 'bi-file-text', $(this).data('value'));
    });

    // Details Button Handler
    $('#ceTable').on('click', '.details-btn', function(e) {
        e.stopPropagation();
        var id = $(this).data('id');
        window.open('ce_details.php?id=' + id, '_blank');
    });

    // Function to open update modal
    function openUpdateModal(id, field, label, iconClass, currentValue) {
        // Remove existing modal if any
        $('#updateModal').remove();
        
        // Create modal HTML
        var modalHtml = `
        <div class="modal fade" id="updateModal" tabindex="-1">
            <div class="modal-dialog modal-dialog-centered">
                <div class="modal-content">
                    <div class="modal-header bg-primary text-white">
                        <h5 class="modal-title">
                            <i class="bi ${iconClass} me-2"></i>Update ${label}
                        </h5>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <div class="mb-3">
                            <label for="updateValue" class="form-label">${label} Count:</label>
                            <div class="input-group">
                                <span class="input-group-text"><i class="bi ${iconClass}"></i></span>
                                <input type="number" class="form-control" id="updateValue" 
                                       value="${currentValue}" min="0" step="1" required>
                            </div>
                            <div class="form-text">Current value: ${currentValue}</div>
                        </div>
                        <div class="alert alert-info">
                            <i class="bi bi-info-circle me-2"></i> 
                            This will update the count and record your username as the editor.
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                            <i class="bi bi-x-circle me-1"></i>Cancel
                        </button>
                        <button type="button" class="btn btn-primary" id="confirmUpdate">
                            <i class="bi bi-check-circle me-1"></i>Update
                        </button>
                    </div>
                </div>
            </div>
        </div>`;
        
        $('body').append(modalHtml);
        
        // Show modal
        var modal = new bootstrap.Modal(document.getElementById('updateModal'));
        modal.show();
        
        // Focus on input
        $('#updateValue').focus().select();
        
        // Handle update confirmation
        $('#confirmUpdate').off('click').on('click', function() {
            var newValue = $('#updateValue').val();
            var button = $(this);
            
            if (newValue === currentValue.toString()) {
                modal.hide();
                return;
            }
            
            button.prop('disabled', true).html('<i class="bi bi-arrow-clockwise spin me-1"></i>Updating...');
            
            var postData = {
                id: id,
                [field]: newValue,
                update_ce_date: 1,
                update_tracking: 1,
                set_check_flag: (field !== 'word_count' ? 2 : 0)
            };
            
            $.ajax({
                url: 'ce_update.php',
                type: 'POST',
                data: postData,
                dataType: 'json',
                success: function(res) {
                    if (res.success) {
                        // Update the table
                        table.ajax.reload(null, false);
                        
                        modal.hide();
                        showSuccessToast(`${label} updated successfully by ${res.done_by}`);
                    } else {
                        alert(res.error || 'Update failed');
                        button.prop('disabled', false).html('<i class="bi bi-check-circle me-1"></i>Update');
                    }
                },
                error: function() {
                    alert('Server communication failed');
                    button.prop('disabled', false).html('<i class="bi bi-check-circle me-1"></i>Update');
                }
            });
        });
        
        // Allow Enter key to confirm
        $('#updateValue').on('keyup', function(e) {
            if (e.key === 'Enter') {
                $('#confirmUpdate').click();
            }
        });
    }
});
</script>

<style>
.editable-cell {
    cursor: pointer;
    background-color: #f8f9fa !important;
}

.editable-cell:hover {
    background-color: #e9ecef !important;
}

.btn-group-sm .btn {
    padding: 0.25rem 0.5rem;
    font-size: 0.875rem;
    border-radius: 0.25rem;
}

.spin {
    animation: spin 1s linear infinite;
}

@keyframes spin {
    0% { transform: rotate(0deg); }
    100% { transform: rotate(360deg); }
}
</style>

<?php require 'footer.php'; ?>