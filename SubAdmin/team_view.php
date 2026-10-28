<?php 
// employee_group_concat.php
require 'header.php';
require 'db.php';

if (session_status() === PHP_SESSION_NONE) session_start();

// --- PHP Database Query (Modified) ---
// Note: We use '::' to separate employee details (Name::ID) and '|||' to separate records.
$sql = "
    SELECT 
        tl.employee_name AS team_leader,
        GROUP_CONCAT(
            CONCAT(e.employee_name, '::', e.employee_id) 
            SEPARATOR '|||'
        ) AS executives
    FROM employee_master e
    INNER JOIN employee_master tl ON e.reporting_manager = tl.employee_name
    WHERE e.user_role_id = 4
    GROUP BY e.reporting_manager
    ORDER BY tl.employee_name;
";

$result = $mysqli->query($sql);
$data = [];
while($row = $result->fetch_assoc()){
    $data[] = $row;
}
$json_data = json_encode($data); 
$mysqli->close();
?>

<div class="main-content">
    <div class="job-master-container container-fluid py-4">
        
        <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
        <link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/jquery.dataTables.min.css">
        <link rel="stylesheet" href="https://cdn.datatables.net/v/bs5/dt-1.13.6/datatables.min.css">
        <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
        
        <style>
            .job-master-container {
                padding: 20px;
                background-color: #fff;
                border-radius: 8px;
                box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
            }
            
            #teamTable thead th {
                /* background-color: #495057;  */
                background-color: #707981 !important;
                color: white;
                font-weight: bold;
                border-bottom: 2px solid #495057;
            }
            
            /* Team Leader Row Styling */
            .team-leader-row {
                background-color: #f8f9fa;
                font-weight: 600;
            }
            
            .team-leader-row:hover {
                background-color: #e9ecef !important;
                cursor: pointer;
            }

            /* Expand/Collapse Icon */
            .dt-control {
                cursor: pointer;
                text-align: center;
                width: 30px;
            }

            /* Child Row Styling */
            .executives-list {
                padding: 15px 20px;
                background-color: #f8f9fa;
                border-left: 4px solid #495057;
                margin: 5px 0;
            }
            
            .executive-item {
                display: flex;
                align-items: center;
                padding: 8px 12px;
                margin: 5px 0;
                background-color: white;
                border-radius: 6px;
                border-left: 3px solid #28a745;
                box-shadow: 0 1px 3px rgba(0,0,0,0.1);
            }
            
            .executive-item:hover {
                background-color: #f1f3f4;
            }
            
            .executive-icon {
                color: #28a745;
                margin-right: 10px;
                font-size: 14px;
            }
            
            .executive-info {
                flex: 1;
            }
            
            .team-badge {
                background-color: #495057;
                color: white;
                padding: 3px 8px;
                border-radius: 4px;
                font-size: 12px;
                margin-left: 10px;
            }
            
            .no-executives {
                color: #6c757d;
                font-style: italic;
                padding: 10px;
            }
        </style>
    
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h2 class="mb-0">Team Leader and Executives Hierarchy</h2>
            <div class="text-muted">
                <small>Click <i class="fas fa-plus-circle text-success"></i> to expand team details</small>
            </div>
        </div>
        
        <table id="teamTable" class="table table-striped table-hover">
            <thead>
                <tr>
                    <th class="text-center" style="width: 5%;"></th> 
                    <th style="width: 35%;">Team Leader</th>
                    <th style="width: 25%;">Team Size</th>
                    <th style="width: 35%;">Team Details</th>
                </tr>
            </thead>
            <tbody>
            </tbody>
        </table>

    </div>
</div>

<script src="https://code.jquery.com/jquery-3.7.0.min.js"></script>
<script src="https://cdn.datatables.net/v/bs5/dt-1.13.6/datatables.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>

<script>
    var teamData = <?= $json_data ?>;

    /**
     * Renders the detailed list of executives for a team.
     * Parses data formatted as "Name::ID|||Name::ID..."
     */
    function formatTeamDetails(d) {
        // 1. Split the main list using the '|||' separator
        var executivesArray = d.executives.split('|||').filter(Boolean);
        var teamSize = executivesArray.length;
        
        var listHtml = '<div class="executives-list">';
        
        if (teamSize > 0) {
            listHtml += '<div class="mb-3"><strong>Team Members (' + teamSize + '):</strong></div>';
            
            executivesArray.forEach(function(executive, index) {
                // 2. Split the individual executive record using the '::' detail separator
                var execParts = executive.split('::'); 
                var execName = execParts[0] || 'Unknown Executive';
                var execDetail = execParts[1] || 'No ID/Detail'; 
                
                listHtml += '<div class="executive-item">' +
                    '<div class="executive-icon">' +
                        '<i class="fas fa-user-tie"></i>' +
                    '</div>' +
                    '<div class="executive-info">' +
                        '<strong>' + execName + '</strong>' +
                        // Display the detail/ID in a badge
                        '<span class="team-badge">Emp ID: ' + execDetail + '</span>' + 
                    '</div>' +
                '</div>';
            });
        } else {
            listHtml += '<div class="no-executives">' +
                '<i class="fas fa-users me-2"></i>No executives reported to this team leader' +
            '</div>';
        }
        
        listHtml += '</div>';

        return listHtml;
    }

    $(document).ready(function(){
        var table = $('#teamTable').DataTable({
            data: teamData,
            columns: [
                {
                    className: 'dt-control text-center',
                    orderable: false,
                    data: null,
                    defaultContent: '',
                    width: '5%'
                },
                { 
                    data: 'team_leader',
                    width: '35%',
                    render: function(data, type, row) {
                        return '<div class="team-leader-name"><i class="fas fa-crown text-warning me-2"></i>' + data + '</div>';
                    }
                },
                { 
                    data: 'executives',
                    width: '25%',
                    render: function(data, type, row) {
                        var executivesArray = data ? data.split('|||').filter(Boolean) : [];
                        var teamSize = executivesArray.length;
                        var badgeClass = teamSize > 0 ? 'bg-success' : 'bg-secondary';
                        return '<span class="badge ' + badgeClass + '">' + teamSize + ' member(s)</span>';
                    }
                },
                { 
                    data: 'executives',
                    width: '35%',
                    render: function(data, type, row) {
                        var executivesArray = data ? data.split('|||').filter(Boolean) : [];
                        if (executivesArray.length > 0) {
                            return '<small class="text-muted">Click expand icon to view team details</small>';
                        } else {
                            return '<small class="text-muted">No team members assigned</small>';
                        }
                    }
                }
            ],
            renderer: 'bootstrap',
            order: [[1, 'asc']],
            responsive: true,
            language: {
                emptyTable: "No team data available",
                zeroRecords: "No matching teams found"
            }
        });

        // Add click event for expand/collapse
        $('#teamTable tbody').on('click', 'td.dt-control', function () {
            var tr = $(this).closest('tr');
            var row = table.row(tr);

            if (row.child.isShown()) {
                row.child.hide();
                tr.removeClass('shown');
            } else {
                row.child(formatTeamDetails(row.data())).show();
                tr.addClass('shown');
            }
        });
        
        // Custom CSS for expand/collapse icons
        $('head').append('<style>' +
            'td.dt-control::before { ' +
                'content: "\\f055"; font-family: "Font Awesome 5 Free"; font-weight: 900; color: #28a745; font-size: 16px; }' +
            'tr.shown td.dt-control::before { ' +
                'content: "\\f056"; color: #dc3545; }' +
        '</style>');

    });
</script>

<?php 
// require 'footer.php';
?>