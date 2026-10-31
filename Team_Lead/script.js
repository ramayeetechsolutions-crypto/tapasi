$(document).ready(function() {

    // Global selector for editable cells
    var editable_cell_selector = '.editable[data-job_id]';

    $(document).on('click', editable_cell_selector, function() {
        var cell = $(this);
        // Prevent editing if the input field is already open
        if (cell.find('input').length > 0) {
            return;
        }

        var currentValue = cell.text().trim();
        var column = cell.data('column');
        var inputType = (column == 'eq') ? 'text' : 'number';

        // Create the input field
        var input = $('<input>', {
            type: inputType,
            value: currentValue,
            class: 'form-control form-control-sm'
        });

        // Replace the cell content with the input field
        cell.html(input);
        input.focus();

        // Save on blur or Enter key press
        input.on('blur keydown', function(e) {
            if (e.type === 'blur' || e.key === 'Enter') {
                e.preventDefault();
                var newValue = input.val().trim();
                
                // If value hasn't changed or is empty, just restore the text
                if (newValue === currentValue || newValue === "") {
                    cell.text(currentValue);
                    return;
                }

                // If value has changed, save it
                saveData(cell, column, newValue);
            }
        });
    });

    function saveData(cell, column, value) {
        var jobId = cell.data('job_id');
        var ceId = cell.data('ce_id'); // This will be 0 for new entries
        var originalValue = cell.text().trim(); // Capture current displayed value

        // Display a loader or temporary message
        cell.html('<span class="saving-message">Saving...</span>');

        $.ajax({
            url: 'ce.php', // *** Change to your actual PHP handler file ***
            method: 'POST',
            dataType: 'json',
            data: {
                ceId: ceId,
                jobId: jobId,
                column: column,
                value: value
            },
            success: function(response) {
                if (response.success) {
                    // Update cell with the new value
                    cell.text(value);

                    // If a NEW entry was inserted, update all data-ce_id attributes in this row
                    if (ceId == 0 && response.newCeId) {
                        cell.closest('tr').find('td[data-job_id="' + jobId + '"]').each(function() {
                             $(this).data('ce_id', response.newCeId);
                             $(this).attr('data-ce_id', response.newCeId);
                        });
                        console.log('New CE record created with ID: ' + response.newCeId);
                    }
                } else {
                    // Display error and revert to original value
                    alert('Error saving data: ' + (response.error || 'Network error or PHP failure. Check console.'));
                    cell.text(originalValue);
                    console.error('Server Error:', response.error);
                }
            },
            error: function(xhr, status, error) {
                // Revert to original value on network/server error
                cell.text(originalValue);
                alert('AJAX Error: Could not connect to the server or script failed completely.');
                console.error('AJAX Failure:', status, error, xhr.responseText);
            }
        });
    }
});