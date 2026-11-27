<?php 
// employee_group_concat.php
if (session_status() === PHP_SESSION_NONE) { session_start(); }
require 'db.php';
require 'header.php';

$ename = isset($_SESSION["ename"]) ? $_SESSION["ename"] : '';

// 1. Fetch ALL processes to create a mapping dictionary (ID => Name)
// This avoids database Collation errors and allows handling comma-separated IDs easily!
$pm_sql = "SELECT process_id, process_name FROM process_master";
$pm_result = $mysqli->query($pm_sql);
$process_map =[];

if ($pm_result) {
    while($row = $pm_result->fetch_assoc()) {
        if (!empty($row['process_name'])) {
            // Map both ID and Name to the proper Process Name to ensure safety
            $process_map[$row['process_id']] = trim($row['process_name']);
            $process_map[$row['process_name']] = trim($row['process_name']);
        }
    }
}
$json_process_map = json_encode($process_map);


// 2. Main Query for Teams & Executives (Without messy JOINs on Process Master)
$sql = "
    SELECT DISTINCT
        tl.employee_name AS team_leader,
        tl.employee_username AS team_leader_username,
        COALESCE(
            GROUP_CONCAT(
                DISTINCT CONCAT(
                    COALESCE(e.employee_name, 'Unknown'), '::', 
                    COALESCE(e.employee_username, 'N/A'), '::', 
                    COALESCE(e.process, ''), '::',
                    COALESCE(e.process1, '')
                ) 
                
                ORDER BY e.employee_username ASC
                SEPARATOR '|||'
            ), ''
        ) AS executives,
        COUNT(DISTINCT e.employee_id) AS team_size
    FROM employee_master e
    INNER JOIN employee_master tl 
        ON CAST(e.reporting_manager AS CHAR) COLLATE utf8mb4_unicode_ci = CAST(tl.employee_name AS CHAR) COLLATE utf8mb4_unicode_ci
    WHERE e.user_role_id = 4  
      AND CAST(tl.employee_name AS CHAR) COLLATE utf8mb4_unicode_ci LIKE '%$ename%' 
      AND e.employee_username != 'test1'
    GROUP BY tl.employee_username, tl.employee_name
    ORDER BY tl.employee_name;
";

$result = $mysqli->query($sql);

if (!$result) {
    die("Query failed: " . $mysqli->error);
}

$data =[];
while($row = $result->fetch_assoc()){
    $data[] = $row;
}

if (empty($data)) {
    $data =[];
}

$json_data = json_encode($data);
$mysqli->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Team Hierarchy</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/jquery.dataTables.min.css">
    <link rel="stylesheet" href="https://cdn.datatables.net/v/bs5/dt-1.13.6/datatables.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <!-- Global Tapasi Stylesheet -->
    <link rel="stylesheet" href="../custom_style.css?v=<?= file_exists(__DIR__ . '/../custom_style.css') ? filemtime(__DIR__ . '/../custom_style.css') : 1 ?>">
    
    <style>
        .job-master-container {
            padding: 20px;
            background-color: #fff;
            border-radius: 8px;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
            margin: 20px;
        }
        
        #teamTable thead th {
            background-color: #5e6b77 !important; /* Slate Grey */
            color: white !important;
            font-weight: bold;
            border-bottom: 2px solid #4a545e !important;
            border-right: 1px solid #4a545e !important;
            padding: 12px 15px;
        }
        #teamTable thead th:last-child {
            border-right: none !important;
        }
        #teamTable thead tr th:first-child {
            border-top-left-radius: 8px;
        }
        #teamTable thead tr th:last-child {
            border-top-right-radius: 8px;
        }
        
        #teamTable > tbody > tr.team-leader-row { transition: background-color 0.2s ease; }
        #teamTable > tbody > tr.team-leader-row:hover > td,
        .team-leader-row:hover > td { background-color: #c4cbd3 !important; cursor: pointer; }

        /* Child Row Styling - prevent entire expanded row from turning blue/grey on hover */
        #teamTable > tbody > tr:not(.team-leader-row),
        #teamTable > tbody > tr:not(.team-leader-row) > td,
        #teamTable > tbody > tr:not(.team-leader-row):hover,
        #teamTable > tbody > tr:not(.team-leader-row):hover > td,
        #teamTable tbody tr.child,
        #teamTable tbody tr.child:hover,
        #teamTable tbody tr.child > td,
        #teamTable tbody tr.child:hover > td,
        #teamTable tbody tr.shown + tr > td,
        #teamTable tbody tr.shown + tr:hover > td {
            background-color: transparent !important;
            cursor: default !important;
            box-shadow: none !important;
        }

        .dt-control {
            cursor: pointer;
            text-align: center;
            width: 40px;
        }

        .executives-list {
            padding: 20px;
            background-color: #f8f9fa;
            border-left: 4px solid #3498db;
            margin: 5px 0;
            border-radius: 0 8px 8px 0;
        }

        /* Inner Table Styling for Detail View */
        .inner-table {
            border: 1px solid #e9ecef;
            border-radius: 6px;
            background-color: white;
            margin-bottom: 0;
        }
        .inner-table thead,
        .inner-table thead tr,
        .inner-table thead th,
        .inner-table thead.table-light,
        .inner-table thead.table-light tr,
        .inner-table thead.table-light th,
        .inner-table th {
            background-color: #5e6b77 !important;
            background: #5e6b77 !important;
            color: #ffffff !important;
            font-weight: 700 !important;
            border-bottom: 2px solid #4a545e !important;
            border-right: 1px solid #4a545e !important;
            padding: 10px 14px !important;
            box-shadow: none !important;
            --bs-table-bg: #5e6b77 !important;
            --bs-table-accent-bg: transparent !important;
        }

        .inner-table thead:hover,
        .inner-table thead tr:hover,
        .inner-table thead th:hover,
        .inner-table thead:hover th,
        .inner-table thead tr:hover th,
        .inner-table thead tr:hover td,
        #teamTable tbody tr:hover .inner-table thead,
        #teamTable tbody tr:hover .inner-table thead th,
        #teamTable tbody tr.child:hover .inner-table thead,
        #teamTable tbody tr.child:hover .inner-table thead th,
        #teamTable tbody tr:not(.child):hover .inner-table thead th {
            background-color: #5e6b77 !important;
            background: #5e6b77 !important;
            color: #ffffff !important;
            box-shadow: none !important;
            --bs-table-bg: #5e6b77 !important;
            --bs-table-accent-bg: transparent !important;
            cursor: default !important;
        }
        .inner-table thead th:last-child,
        .inner-table thead.table-light th:last-child,
        .inner-table th:last-child {
            border-right: none !important;
        }
        .inner-table thead tr th:first-child,
        .inner-table thead.table-light tr th:first-child {
            border-top-left-radius: 6px;
        }
        .inner-table thead tr th:last-child,
        .inner-table thead.table-light tr th:last-child {
            border-top-right-radius: 6px;
        }
        .inner-table td,
        .inner-table tbody tr td {
            vertical-align: middle;
            border-bottom: 1px solid #f1f3f5;
            background-color: #ffffff !important;
            background: #ffffff !important;
            color: #212529 !important;
            transition: background-color 0.15s ease !important;
        }
        .inner-table tbody tr {
            transition: background-color 0.15s ease !important;
        }
        .inner-table tbody tr:hover td,
        .inner-table tbody tr:hover > td,
        table.inner-table tbody tr:hover td,
        table.inner-table tbody tr:hover > td,
        #teamTable .inner-table tbody tr:hover td,
        #teamTable .inner-table tbody tr:hover > td {
            background-color: #c4cbd3 !important;
            background: #c4cbd3 !important;
            color: #212529 !important;
            box-shadow: none !important;
            cursor: pointer !important;
        }
        
        .team-badge {
            background-color: #3498db;
            color: white;
            padding: 5px 12px;
            border-radius: 4px;
            font-size: 12px;
            font-weight: 600;
        }
        
        .process-badge {
            background-color: #f1c40f;
            color: #2c3e50;
            padding: 5px 12px;
            border-radius: 4px;
            font-size: 12px;
            font-weight: 700;
            border: 1px solid #d4ac0d;
        }
        
        .xml-process-pill {
            font-size: 0.75rem;
            padding: 0.35em 0.8em;
            background-color: #f8f9fa;
            color: #495057;
            border: 1px solid #ced4da;
            border-radius: 50rem;
        }

        .no-executives {
            color: #7f8c8d;
            font-style: italic;
            padding: 15px;
            text-align: center;
            background-color: #ecf0f1;
            border-radius: 6px;
        }
        
        .team-size-badge {
            font-size: 14px;
            padding: 6px 12px;
        }
        
        .page-header {
            /* background: linear-gradient(135deg, #2c3e50, #4a6491); */
             background: #5e6b77;
            color: white;
            padding: 20px;
            border-radius: 8px;
            margin-bottom: 30px;
        }
        
        .fade-in { animation: fadeIn 0.3s ease-out; }
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(-10px); }
            to { opacity: 1; transform: translateY(0); }
        }
        
        .dt-control:before {
            font-family: "Font Awesome 5 Free";
            font-weight: 900;
            content: "\f055";
            color: #27ae60;
            font-size: 18px;
            transition: transform 0.3s ease;
        }
        tr.shown .dt-control:before {
            content: "\f056";
            color: #e74c3c;
            transform: rotate(180deg);
        }
        
        .empty-state {
            text-align: center;
            padding: 40px;
            color: #7f8c8d;
        }
        .empty-state i {
            font-size: 48px;
            margin-bottom: 15px;
            opacity: 0.3;
        }
    </style>
</head>
<body>
    <div class="main-content" style="padding-top: 0 !important; margin-top: 0 !important;">
        <div class="job-master-container">
            <div class="page-header">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <h2 class="text-white"><i class="fas fa-sitemap me-3 text-white"></i>Team Master</h2>
                        <div class="subtitle text-white">View organizational structure, team sizes, and assigned XML processes</div>
                    </div>
                    <div class="text-white-50">
                        <small><i class="fas fa-info-circle me-1"></i>Click <i class="fas fa-plus-circle"></i> to expand team details</small>
                    </div>
                </div>
            </div>
            
            <?php if (empty($data)): ?>
                <div class="empty-state">
                    <i class="fas fa-users-slash"></i>
                    <h4>No Team Data Available</h4>
                    <p>There are no team leaders or executives to display at the moment.</p>
                    <a href="javascript:location.reload()" class="btn btn-primary mt-3">
                        <i class="fas fa-sync-alt me-2"></i>Refresh
                    </a>
                </div>
            <?php else: ?>
                <table id="teamTable" class="table table-striped table-hover align-middle">
                    <thead>
                        <tr>
                            <th class="text-center" style="width: 50px;"></th> 
                            <th title="Click to sort by Team Leader">Team Leader</th>
                            <th title="Click to sort by Team Size">Team Size</th>
                            <th title="Team XML Processes (Non-sortable)">XML Processes</th> <!-- Team's XML Processes -->
                        </tr>
                    </thead>
                    <tbody>
                        <!-- Data will be populated by DataTables -->
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
    </div>

    <script src="https://code.jquery.com/jquery-3.7.0.min.js"></script>
    <script src="https://cdn.datatables.net/v/bs5/dt-1.13.6/datatables.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>

    <script>
        var teamData = <?= $json_data ?>;
        var processMap = <?= $json_process_map ?>; // Dictionary of ID -> Process Name
        
        // Helper function: Takes raw process strings, splits commas, maps them to names, and removes duplicates
        function getMappedProcesses(proc1, proc2) {
            var rawList =[];
            if (proc1) rawList = rawList.concat(proc1.split(','));
            if (proc2) rawList = rawList.concat(proc2.split(','));
            
            var namesList =[];
            rawList.forEach(function(item) {
                var p = item.trim();
                if (p !== ''&& p !== '0') {
                    // Look up the name from process_master dict, or fallback to raw value
                    namesList.push(processMap[p] ? processMap[p] : p);
                }
            });
            
            // Return only Unique values (Fixes "XML, XML")
            return [...new Set(namesList)];
        }

        // Formats the expanded inner table for the team
        function formatTeamDetails(d) {
            var listHtml = '<div class="executives-list fade-in">';
            
            if (d.executives && d.executives.trim() !== '') {
                var executivesArray = d.executives.split('|||').filter(function(item) {
                    return item.trim() !== '';
                });
                
                var teamSize = executivesArray.length;
                
                // if (teamSize > 0) {
                //     listHtml += '<div class="mb-4">' +
                //         '<h6 class="fw-bold mb-2"><i class="fas fa-user-tie me-2 text-primary"></i>Team Leader: ' + d.team_leader + '</h6>' +
                //         '<div class="d-flex align-items-center mb-3 flex-wrap">' +
                //             '<span class="badge bg-primary me-3 mb-2">Team Leader ID: ' + (d.team_leader_username || 'N/A') + '</span>' +
                //             '<span class="badge bg-success me-3 mb-2">Team Size: ' + teamSize + '</span>' +
                //         '</div>' +
                //     '</div>';
                if (teamSize >= 0) { // Changed to >=0 to allow adding members to empty teams
                    listHtml += '<div class="d-flex justify-content-between align-items-start mb-4">' +
                        '<div>' +
                            '<h6 class="fw-bold mb-2"><i class="fas fa-user-tie me-2 text-primary"></i>Team Leader: ' + d.team_leader + '</h6>' +
                            '<div class="d-flex align-items-center mb-0 flex-wrap">' +
                                '<span class="badge bg-primary me-3 mb-2">Team Leader ID: ' + (d.team_leader_username || 'N/A') + '</span>' +
                                '<span class="badge bg-success me-3 mb-2">Team Size: ' + teamSize + '</span>' +
                            '</div>' +
                        '</div>' +
                        '<div>' +
                            /* Professional Grey Button */
                            '<a href="add_team_member.php?tl_name=' + encodeURIComponent(d.team_leader) + '" ' +
                            'class="btn btn-success shadow-sm text-white px-4 py-2" style="background-color: #198754; border-radius: 6px; font-weight: 500;">' +
                            '<i class="fas fa-user-plus me-2"></i>Add Team Member</a>' +
                        '</div>' +
                    '</div>';
                    
                    listHtml += '<h6 class="fw-bold mb-3 text-secondary">Team Members & Assigned Processes:</h6>';
                    
                    // Create the detailed Inner Table
                    listHtml += '<div class="table-responsive shadow-sm rounded">' +
                                '<table class="table table-hover inner-table w-100">' +
                                // '<thead class="table-light">' +
                                //     '<tr>' +
                                //         '<th class="text-center" style="width: 60px;">S.No</th>' +
                                //         '<th style="width: 160px;">Employee ID</th>' +
                                //         '<th>Name</th>' +
                                //         '<th>Process Details</th>' +
                                //     '</tr>' +
                                // '</thead>' +
                                '<thead style="background-color: #5e6b77 !important; color: #ffffff !important;">' +
                                    '<tr>' +
                                        '<th class="text-center fw-bold" style="width: 60px; background-color: #5e6b77 !important; color: #ffffff !important; border-bottom: 2px solid #4a545e !important; box-shadow: none !important;">S.No</th>' +
                                        '<th style="width: 130px; background-color: #5e6b77 !important; color: #ffffff !important; border-bottom: 2px solid #4a545e !important; box-shadow: none !important;">Employee ID</th>' +
                                        '<th style="white-space: nowrap; min-width: 180px; background-color: #5e6b77 !important; color: #ffffff !important; border-bottom: 2px solid #4a545e !important; box-shadow: none !important;">Name</th>' +
                                        '<th style="background-color: #5e6b77 !important; color: #ffffff !important; border-bottom: 2px solid #4a545e !important; box-shadow: none !important;">Process Details</th>' +
                                        '<th class="text-center" style="width: 100px; background-color: #5e6b77 !important; color: #ffffff !important; border-bottom: 2px solid #4a545e !important; box-shadow: none !important;">Action</th>' +
                                    '</tr>' +
                                '</thead>' +
                                '<tbody>';
                    
                    executivesArray.forEach(function(executive, index) {
                        var execParts = executive.split('::');
                        var execName = execParts[0] ? execParts[0].trim() : 'Unknown';
                        var execUsername = execParts[1] ? execParts[1].trim() : 'N/A';
                        
                        // Parse mapped processes
                        var mappedProcesses = getMappedProcesses(execParts[2], execParts[3]);
                        
                        var processBadges = '<span class="text-muted small fw-bold">Unassigned</span>';
                        if (mappedProcesses.length > 0) {
                            processBadges = mappedProcesses.map(function(p) {
                                return '<span class="process-badge me-2 mb-1 d-inline-block"><i class="fas fa-cogs me-1"></i> ' + p + '</span>';
                            }).join('');
                        }
                        
                        // listHtml += '<tr>' +
                        //     '<td class="text-center fw-bold text-muted">' + (index + 1) + '</td>' +
                        //     '<td><span class="team-badge">' + execUsername + '</span></td>' +
                        //     '<td><div class="d-flex align-items-center"><i class="fas fa-user text-success me-2"></i> <strong>' + execName + '</strong></div></td>' +
                        //     '<td>' + processBadges + '</td>' +
                        // '</tr>';
                        listHtml += '<tr>' +
                            '<td class="text-center fw-bold">' + (index + 1) + '</td>' +
                            '<td><span class="team-badge">' + execUsername + '</span></td>' +
                            '<td style="white-space: nowrap;"><div class="d-flex align-items-center"><i class="fas fa-user text-success me-2"></i> <strong>' + execName + '</strong></div></td>' +
                            '<td>' + processBadges + '</td>' +
                            '<td class="text-center">' +
                                '<a href="add_team_member.php?mode=edit&emp_username=' + execUsername + '&tl_name=' + encodeURIComponent(d.team_leader) + '" ' +
                                'class="btn btn-sm btn-edit" title="Edit">' +
                                '<i class="fas fa-edit"></i></a>' +
                            '</td>' +
                        '</tr>';
                    });
                    
                    listHtml += '</tbody></table></div>';
                    
                } else {
                    listHtml += getEmptyState();
                }
            } else {
                listHtml += getEmptyState();
            }
            
            listHtml += '</div>';
            return listHtml;
        }

        function getEmptyState() {
            return '<div class="no-executives">' +
                '<i class="fas fa-users-slash fa-2x mb-3"></i>' +
                '<h6>No Team Members</h6>' +
                '<p>This team leader currently has no executives assigned.</p>' +
            '</div>';
        }

        $(document).ready(function(){
            if (teamData.length === 0) return;

            var table = $('#teamTable').DataTable({
                data: teamData,
                columns:[
                    {
                        className: 'dt-control text-center',
                        orderable: false,
                        data: null,
                        defaultContent: '',
                        width: '50px',
                        render: function(data, type, row, meta) {
                            return '<div class="expand-icon"><i class="fas fa-plus-circle text-success"></i></div>';
                        }
                    },
                    { 
                        data: 'team_leader',
                        render: function(data, type, row) {
                            if (type === 'sort' || type === 'type') {
                                return data ? data.toLowerCase() : '';
                            }
                            if (!data) return '<span class="text-muted">Unknown</span>';
                            return '<div class="d-flex align-items-center">' +
                                '<div class="me-2"><i class="fas fa-user-tie text-primary"></i></div>' +
                                '<div>' +
                                    '<strong class="d-block">' + data + '</strong>' +
                                    '<small class="text-muted">Team Leader ID: ' + (row.team_leader_username || 'N/A') + '</small>' +
                                '</div>' +
                            '</div>';
                        }
                    },
                    { 
                        data: 'team_size',
                        type: 'num',
                        render: function(data, type, row) {
                            var teamSize = parseInt(data, 10) || 0;
                            if (type === 'sort' || type === 'type') {
                                return teamSize;
                            }
                            var badgeClass = teamSize === 0 ? 'bg-secondary' : (teamSize <= 3 ? 'bg-warning' : (teamSize <= 7 ? 'bg-info' : 'bg-success'));
                            return '<div class="d-flex align-items-center">' +
                                '<span class="badge ' + badgeClass + ' team-size-badge me-2">' + teamSize + ' member' + (teamSize !== 1 ? 's' : '') + '</span>' +
                                '<div class="small text-muted">' + (teamSize > 0 ? '<i class="fas fa-check-circle text-success"></i> Active' : '') + '</div>' +
                            '</div>';
                        }
                    },
                    { 
                        // Automatically extracts only processes containing "XML" specific to this team
                        data: 'executives',
                        orderable: false,
                        render: function(data, type, row) {
                            if (!data || data.trim() === '') return '<span class="text-muted small">None</span>';
                            
                            var executivesArray = data.split('|||').filter(item => item.trim() !== '');
                            var teamProcesses = [];
                            
                            // Loop all members to gather their processes
                            executivesArray.forEach(function(exec) {
                                var execParts = exec.split('::');
                                var memberProcesses = getMappedProcesses(execParts[2], execParts[3]);
                                teamProcesses = teamProcesses.concat(memberProcesses);
                            });
                            
                            // Remove duplicates for the whole team
                            teamProcesses = [...new Set(teamProcesses)];
                            
                            // Filter so we ONLY display ones with "XML" in the name
                            var xmlProcessesOnly = teamProcesses.filter(function(p) {
                                return p.toLowerCase().includes('xml');
                            });
                            
                            if (xmlProcessesOnly.length > 0) {
                                var badges = xmlProcessesOnly.map(function(p) {
                                    return '<span class="xml-process-pill me-1 mb-1 d-inline-block">' + p + '</span>';
                                }).join('');
                                return '<div class="d-flex flex-wrap align-items-center" style="max-width: 400px;">' + badges + '</div>';
                            }
                            
                            return '<span class="text-muted small">None</span>';
                        }
                    }
                ],
                order: [[1, 'asc']],
                responsive: true,
                pageLength: 10,
                lengthMenu:[10, 25, 50, 100],
                language: { emptyTable: "No team data available" },
                initComplete: function() {
                    $('.dataTables_length select').addClass('form-select form-select-sm');
                    $('.dataTables_filter input').addClass('form-control form-control-sm');

                    // PUDHU LOGIC: Page load aagumbodhu pazhaya open state-ah check panni open pandradhu
                    var savedTeam = localStorage.getItem('expandedTeam');
                    if(savedTeam) {
                        setTimeout(function() {
                            table.rows().every(function () {
                                var data = this.data();
                                if (data && data.team_leader_username === savedTeam) {
                                    var tr = $(this.node());
                                    tr.find('td.dt-control').trigger('click');
                                }
                            });
                        }, 100);
                    }
                }
            });

            $('#teamTable tbody').on('click', 'td.dt-control', function () {
                var tr = $(this).closest('tr');
                var row = table.row(tr);
                var icon = $(this).find('i');
                var rowData = row.data();
                
                if (row.child.isShown()) {
                    row.child.hide();
                    tr.removeClass('shown');
                    icon.removeClass('fa-minus-circle text-danger').addClass('fa-plus-circle text-success');
                    localStorage.removeItem('expandedTeam');
                } else {
                    row.child(formatTeamDetails(rowData), 'child').show();
                    tr.addClass('shown');
                    icon.removeClass('fa-plus-circle text-success').addClass('fa-minus-circle text-danger');
                    if(rowData && rowData.team_leader_username) {
                        localStorage.setItem('expandedTeam', rowData.team_leader_username);
                    }
                }
            });
            
            $('#teamTable tbody').on('mouseenter', 'tr', function() {
                if (!$(this).hasClass('shown')) $(this).find('.expand-icon i').addClass('fa-beat');
            }).on('mouseleave', 'tr', function() {
                $(this).find('.expand-icon i').removeClass('fa-beat');
            });
        });
    </script>
</body>
</html>