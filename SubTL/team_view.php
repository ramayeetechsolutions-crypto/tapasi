<?php 
// employee_group_concat.php
require 'db.php';
require 'header.php';


if (session_status() === PHP_SESSION_NONE) session_start();

$ename = $mysqli->real_escape_string($_SESSION["ename"] ?? ($_SESSION["username"] ?? ''));
$sess_user = $mysqli->real_escape_string($_SESSION["username"] ?? '');
$sess_uid = (int)($_SESSION['uid'] ?? 0);

// 1. Fetch ALL processes to create a mapping dictionary (ID => Name)
$pm_sql = "SELECT process_id, process_name FROM process_master";
$pm_result = $mysqli->query($pm_sql);
$process_map = [];

if ($pm_result) {
    while($row = $pm_result->fetch_assoc()) {
        if (!empty($row['process_name'])) {
            $process_map[$row['process_id']] = trim($row['process_name']);
            $process_map[$row['process_name']] = trim($row['process_name']);
        }
    }
}
$json_process_map = json_encode($process_map);

// 2. Main Query for Teams & Executives (Strictly UQ Team Members - SQL Fixed)
$sql = "
    SELECT DISTINCT
        tl.employee_name AS team_leader,
        tl.employee_username AS team_leader_username,
        tl.employee_username AS team_leader_id,
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
    FROM employee_master tl
    LEFT JOIN employee_master e 
        ON e.employee_id != tl.employee_id
    LEFT JOIN team_master t 
        ON e.team_id = t.team_id
    WHERE (
        tl.employee_id = $sess_uid
        OR tl.employee_username = '$sess_user' 
        OR CAST(tl.employee_name AS CHAR) COLLATE utf8mb4_unicode_ci LIKE '%$ename%'
    )
    AND (
        e.employee_id IS NULL 
        OR (
            (e.user_role_id = 4 OR e.user_role_id = 11)
            AND (e.status = 'Active' OR e.status IS NULL)
            AND (
                t.team_name LIKE '%UQ%' 
                OR e.team_id = 2
                OR e.process LIKE '%UQ%' 
                OR EXISTS (
                    SELECT 1 FROM process_master pm 
                    WHERE FIND_IN_SET(pm.process_id, e.process) 
                    AND pm.process_name LIKE '%UQ%'
                )
                OR CAST(e.reporting_manager AS CHAR) COLLATE utf8mb4_unicode_ci = CAST(tl.employee_name AS CHAR) COLLATE utf8mb4_unicode_ci
                OR CAST(e.reporting_manager AS CHAR) COLLATE utf8mb4_unicode_ci = CAST(tl.employee_username AS CHAR) COLLATE utf8mb4_unicode_ci
            )
        )
    )
    GROUP BY tl.employee_username, tl.employee_name
    ORDER BY tl.employee_name;
";

$result = $mysqli->query($sql);

if (!$result) {
    die("Query failed: " . $mysqli->error);
}

$data = [];
while($row = $result->fetch_assoc()){
    $data[] = $row;
}

if (empty($data)) {
    $data = [];
}

$json_data = json_encode($data);
$mysqli->close();

// --- PHP Database Query (Corrected) ---
// $ename = isset($_SESSION["ename"]) ? $_SESSION["ename"] : '';

// // SQL Query to get team leaders and their executives
// $sql = "
//     SELECT DISTINCT
//         tl.employee_name AS team_leader,
//         tl.employee_id AS team_leader_id,
//         COALESCE(
//             GROUP_CONCAT(
//                 DISTINCT CONCAT(e.employee_name, '::', e.employee_id) 
//                 ORDER BY e.employee_name
//                 SEPARATOR '|||'
//             ), ''
//         ) AS executives,
//         COUNT(DISTINCT e.employee_id) AS team_size
//     FROM employee_master e
//     INNER JOIN employee_master tl ON e.reporting_manager = tl.employee_name
//     WHERE e.user_role_id = 4  -- Assuming 4 is executive role
//       AND tl.employee_name LIKE '%$ename%'  -- Filter by current user if needed
//     GROUP BY tl.employee_id, tl.employee_name
//     ORDER BY tl.employee_name;
// ";

// Alternative: If you want all team leaders regardless of current user
/*
$sql = "
    SELECT DISTINCT
        tl.employee_name AS team_leader,
        tl.employee_id AS team_leader_id,
        COALESCE(
            GROUP_CONCAT(
                DISTINCT CONCAT(e.employee_name, '::', e.employee_id) 
                ORDER BY e.employee_name
                SEPARATOR '|||'
            ), ''
        ) AS executives,
        COUNT(DISTINCT e.employee_id) AS team_size
    FROM employee_master tl
    LEFT JOIN employee_master e ON e.reporting_manager = tl.employee_name 
        AND e.user_role_id = 4
    WHERE tl.user_role_id = 3  -- Assuming 3 is team leader role
    GROUP BY tl.employee_id, tl.employee_name
    ORDER BY tl.employee_name;
";
*/

// $result = $mysqli->query($sql);

// // Check for errors
// if (!$result) {
//     die("Query failed: " . $mysqli->error);
// }

// $data = [];
// while($row = $result->fetch_assoc()){
//     $data[] = $row;
// }

// // If no data, create an empty array
// if (empty($data)) {
//     $data = [];
// }

// $json_data = json_encode($data);
// $mysqli->close();
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
    
    <style>
        .job-master-container {
            padding: 20px;
            background-color: #fff;
            border-radius: 8px;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
            margin: 20px;
        }
        
        #teamTable thead th {
            /* background-color: #2c3e50; */
            background-color: #707981 !important;
            color: white;
            font-weight: bold;
            /* border-bottom: 2px solid #1a252f; */
             border-bottom: 2px solid #707981 !important;
            padding: 12px 15px;
        }
        
        #teamTable tbody tr {
            transition: background-color 0.3s ease;
        }
        
        #teamTable tbody tr:hover {
            background-color: #f5f7fa;
        }
        
        /* Team Leader Row Styling */
        .team-leader-row {
            background-color: #f8f9fa;
            font-weight: 600;
        }
        
        .team-leader-row:hover {
            background-color: #e9ecef !important;
        }

        /* Expand/Collapse Icon */
        .dt-control {
            cursor: pointer;
            text-align: center;
            width: 40px;
        }

        /* Child Row Styling */
        .executives-list {
            padding: 20px;
            background-color: #f8f9fa;
            border-left: 4px solid #3498db;
            margin: 5px 0;
            border-radius: 0 8px 8px 0;
        }
        
        .executive-item {
            display: flex;
            align-items: center;
            padding: 10px 15px;
            margin: 8px 0;
            background-color: white;
            border-radius: 6px;
            border-left: 4px solid #2ecc71;
            box-shadow: 0 2px 4px rgba(0,0,0,0.05);
            transition: transform 0.2s ease, box-shadow 0.2s ease;
        }
        
        .executive-item:hover {
            transform: translateX(5px);
            box-shadow: 0 4px 8px rgba(0,0,0,0.1);
            background-color: #f8f9fa;
        }
        
        .executive-icon {
            color: #2ecc71;
            margin-right: 12px;
            font-size: 16px;
            width: 24px;
            text-align: center;
        }
        
        .executive-info {
            flex: 1;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }
        
        .team-badge {
            background-color: #3498db;
            color: white;
            padding: 4px 10px;
            border-radius: 4px;
            font-size: 12px;
            font-weight: 600;
        }
        
        .team-leader-badge {
            background-color: #e74c3c;
            color: white;
            padding: 4px 10px;
            border-radius: 4px;
            font-size: 12px;
            font-weight: 600;
            margin-left: 10px;
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
        
        /* Header styling */
        .page-header {
            /* background: linear-gradient(135deg, #2c3e50, #4a6491); */
            background-color: #707981 !important;
            color: white;
            padding: 20px;
            border-radius: 8px;
            margin-bottom: 30px;
        }
        
        .page-header h2 {
            margin: 0;
            font-weight: 600;
        }
        
        .page-header .subtitle {
            opacity: 0.9;
            font-size: 0.9rem;
        }
        
        /* Animation for expand/collapse */
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(-10px); }
            to { opacity: 1; transform: translateY(0); }
        }
        
        .fade-in {
            animation: fadeIn 0.3s ease-out;
        }
        
        /* Custom expand/collapse icons */
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
        
        /* Status colors */
        .status-active {
            color: #27ae60;
        }
        
        .status-inactive {
            color: #e74c3c;
        }
        
        /* Empty state */
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
        
        /* Responsive adjustments */
        @media (max-width: 768px) {
            .job-master-container {
                padding: 15px;
                margin: 10px;
            }
            
            .executive-info {
                flex-direction: column;
                align-items: flex-start;
            }
            
            .team-badge {
                margin-top: 5px;
                margin-left: 0;
            }
        }
    </style>
</head>
<body>
    <div class="main-content" style="margin-top: 80px;">
        <div class="job-master-container">
            <div class="page-header">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <h2><i class="fas fa-sitemap me-3"></i>Team Master</h2>
                        <div class="subtitle">View organizational structure, team sizes, and assigned CE processes</div>
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
                <table id="teamTable" class="table table-striped table-hover">
                    <thead>
                        <tr>
                            <th class="text-center" style="width: 50px;"></th> 
                            <th>Team Leader</th>
                            <th>Team Size</th>
                            <th>Team Details</th>
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
        var processMap = <?= $json_process_map ?>; 
        
        // Helper function for Process Map
        function getMappedProcesses(proc1, proc2) {
            var rawList = [];
            if (proc1) rawList = rawList.concat(proc1.split(','));
            if (proc2) rawList = rawList.concat(proc2.split(','));
            
            var namesList = [];
            rawList.forEach(function(item) {
                var p = item.trim();
                if (p !== '' && p !== '0') {
                    namesList.push(processMap[p] ? processMap[p] : p);
                }
            });
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
                
                if (teamSize >= 0) { 
                    listHtml += '<div class="d-flex justify-content-between align-items-start mb-4">' +
                        '<div>' +
                            '<h6 class="fw-bold mb-2"><i class="fas fa-user-tie me-2 text-primary"></i>Team Leader: ' + d.team_leader + '</h6>' +
                            '<div class="d-flex align-items-center mb-0 flex-wrap">' +
                                '<span class="badge bg-primary me-3 mb-2">Team Leader ID: ' + (d.team_leader_username || 'N/A') + '</span>' +
                                '<span class="badge bg-success me-3 mb-2">Team Size: ' + teamSize + '</span>' +
                            '</div>' +
                        '</div>' +
                        '<div>' +
                            '<a href="add_team_member.php?tl_name=' + encodeURIComponent(d.team_leader) + '" ' +
                            'class="btn shadow-sm text-white px-4 py-2" style="background-color: #0d6efd; border-radius: 6px; font-weight: 500;">' +
                            '<i class="fas fa-user-plus me-2"></i>Add Team Member</a>' +
                        '</div>' +
                    '</div>';
                    
                    listHtml += '<h6 class="fw-bold mb-3 text-secondary">Team Members & Assigned Processes:</h6>';
                    
                    listHtml += '<div class="table-responsive shadow-sm rounded">' +
                                '<table class="table table-hover inner-table w-100">' +
                                '<thead class="table-light">' +
                                    '<tr>' +
                                        '<th class="text-center" style="width: 60px;">S.No</th>' +
                                        '<th style="width: 130px;">Employee ID</th>' +
                                        '<th>Name</th>' +
                                        '<th>Process Details</th>' +
                                        '<th class="text-center" style="width: 100px;">Action</th>' +
                                    '</tr>' +
                                '</thead>' +
                                '<tbody>';
                    
                    executivesArray.forEach(function(executive, index) {
                        var execParts = executive.split('::');
                        var execName = execParts[0] ? execParts[0].trim() : 'Unknown';
                        var execUsername = execParts[1] ? execParts[1].trim() : 'N/A';
                        
                        var mappedProcesses = getMappedProcesses(execParts[2], execParts[3]);
                        
                        var processBadges = '<span class="text-muted small fw-bold">Unassigned</span>';
                        if (mappedProcesses.length > 0) {
                            processBadges = mappedProcesses.map(function(p) {
                                return '<span class="process-badge me-2 mb-1 d-inline-block"><i class="fas fa-cogs me-1"></i> ' + p + '</span>';
                            }).join('');
                        }
                        
                        listHtml += '<tr>' +
                            '<td class="text-center fw-bold text-muted">' + (index + 1) + '</td>' +
                            '<td><span class="team-badge">' + execUsername + '</span></td>' +
                            '<td><div class="d-flex align-items-center"><i class="fas fa-user text-success me-2"></i> <strong>' + execName + '</strong></div></td>' +
                            '<td>' + processBadges + '</td>' +
                            '<td class="text-center">' +
                                '<a href="add_team_member.php?mode=edit&emp_username=' + execUsername + '&tl_name=' + encodeURIComponent(d.team_leader) + '" ' +
                                'class="btn btn-sm shadow-sm text-white" style="background-color: #0d6efd; padding: 4px 12px; font-size: 11px;">' +
                                '<i class="fas fa-edit"></i> Edit</a>' +
                            '</td>' +
                        '</tr>';
                    });
                    
                    listHtml += '</tbody></table></div>';
                }
            } else {
                // Keep empty state with Add button for empty teams
                listHtml += '<div class="d-flex justify-content-between align-items-start mb-4">' +
                        '<div>' +
                            '<h6 class="fw-bold mb-2"><i class="fas fa-user-tie me-2 text-primary"></i>Team Leader: ' + d.team_leader + '</h6>' +
                            '<span class="badge bg-secondary mb-2">Team Size: 0</span>' +
                        '</div>' +
                        '<div>' +
                            '<a href="add_team_member.php?tl_name=' + encodeURIComponent(d.team_leader) + '" class="btn btn-primary"><i class="fas fa-user-plus me-2"></i>Add Team Member</a>' +
                        '</div>' +
                    '</div>' +
                    '<div class="no-executives"><i class="fas fa-users-slash fa-2x mb-3"></i><h6>No Team Members</h6></div>';
            }
            
            listHtml += '</div>';
            return listHtml;
        }

        $(document).ready(function(){
            // Only initialize DataTable if we have data
            if (teamData.length === 0) {
                return;
            }

            var table = $('#teamTable').DataTable({
                data: teamData,
                columns: [
                    {
                        className: 'dt-control text-center',
                        orderable: false,
                        data: null,
                        defaultContent: '',
                        width: '50px',
                        render: function(data, type, row, meta) {
                            return '<div class="expand-icon">' +
                                '<i class="fas fa-plus-circle text-success"></i>' +
                            '</div>';
                        }
                    },
                    { 
                        data: 'team_leader',
                        render: function(data, type, row) {
                            if (!data) return '<span class="text-muted">Unknown</span>';
                            
                            return '<div class="d-flex align-items-center">' +
                                '<div class="me-2">' +
                                    '<i class="fas fa-user-tie text-primary"></i>' +
                                '</div>' +
                                '<div>' +
                                    '<strong class="d-block">' + data + '</strong>' +
                                    '<small class="text-muted">' +
                                        'ID: ' + (row.team_leader_id || 'N/A') +
                                    '</small>' +
                                '</div>' +
                            '</div>';
                        }
                    },
                    { 
                        data: 'executives',
                        render: function(data, type, row) {
                            if (!data || data.trim() === '') {
                                return '<span class="badge bg-secondary team-size-badge">0 members</span>';
                            }
                            
                            var executivesArray = data.split('|||').filter(function(item) {
                                return item.trim() !== '';
                            });
                            
                            var teamSize = executivesArray.length;
                            var badgeClass = '';
                            
                            if (teamSize === 0) {
                                badgeClass = 'bg-secondary';
                            } else if (teamSize <= 3) {
                                badgeClass = 'bg-warning';
                            } else if (teamSize <= 7) {
                                badgeClass = 'bg-info';
                            } else {
                                badgeClass = 'bg-success';
                            }
                            
                            return '<div class="d-flex align-items-center">' +
                                '<span class="badge ' + badgeClass + ' team-size-badge me-2">' + teamSize + ' member' + (teamSize !== 1 ? 's' : '') + '</span>' +
                                '<div class="small text-muted">' +
                                    (teamSize > 0 ? '<i class="fas fa-check-circle text-success"></i> Active' : '<i class="fas fa-times-circle text-secondary"></i> Empty') +
                                '</div>' +
                            '</div>';
                        }
                    },
                    { 
                        data: 'executives',
                        render: function(data, type, row) {
                            if (!data || data.trim() === '') {
                                return '<div class="text-muted">' +
                                    '<i class="fas fa-info-circle me-1"></i>' +
                                    '<small>No team members assigned</small>' +
                                '</div>';
                            }
                            
                            var executivesArray = data.split('|||').filter(function(item) {
                                return item.trim() !== '';
                            });
                            
                            var teamSize = executivesArray.length;
                            
                            if (teamSize > 0) {
                                // Show sample of team members
                                var sampleNames = [];
                                executivesArray.slice(0, 2).forEach(function(executive) {
                                    var execParts = executive.split('::');
                                    var execName = execParts[0] ? execParts[0].trim() : '';
                                    if (execName) {
                                        sampleNames.push(execName.split(' ')[0]); // First name only
                                    }
                                });
                                
                                var details = '';
                                if (sampleNames.length > 0) {
                                    details = '<small>Includes: ' + sampleNames.join(', ');
                                    if (teamSize > 2) {
                                        details += ' +' + (teamSize - 2) + ' more';
                                    }
                                    details += '</small>';
                                }
                                
                                return '<div>' +
                                    '<small class="text-muted">' +
                                        '<i class="fas fa-mouse-pointer me-1"></i>Click to expand' +
                                    '</small>' +
                                    '<div class="mt-1">' + details + '</div>' +
                                '</div>';
                            }
                            
                            return '<small class="text-muted">No details available</small>';
                        }
                    }
                ],
                order: [[1, 'asc']],
                responsive: true,
                pageLength: 10,
                lengthMenu: [10, 25, 50, 100],
                language: {
                    emptyTable: "No team data available",
                    zeroRecords: "No matching teams found",
                    info: "Showing _START_ to _END_ of _TOTAL_ teams",
                    infoEmpty: "Showing 0 to 0 of 0 teams",
                    infoFiltered: "(filtered from _MAX_ total teams)",
                    lengthMenu: "Show _MENU_ teams",
                    search: "Search teams:",
                    paginate: {
                        first: "First",
                        last: "Last",
                        next: "Next",
                        previous: "Previous"
                    }
                },
                initComplete: function() {
                    // Add custom styling after table initialization
                    $('.dataTables_length select').addClass('form-select form-select-sm');
                    $('.dataTables_filter input').addClass('form-control form-control-sm');

                    // Page load aagumbodhu pazhaya open state-ah check panni open pandradhu
                    var savedTeam = localStorage.getItem('expandedTeam_SubTL');
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

            // Add click event for expand/collapse
            $('#teamTable tbody').on('click', 'td.dt-control', function () {
                var tr = $(this).closest('tr');
                var row = table.row(tr);
                var rowData = row.data(); // Data edukurom
                var icon = $(this).find('i');
                
                if (row.child.isShown()) {
                    row.child.hide();
                    tr.removeClass('shown');
                    icon.removeClass('fa-minus-circle text-danger').addClass('fa-plus-circle text-success');
                    localStorage.removeItem('expandedTeam_SubTL'); // Close panna memory la irunthu thookidrom
                } else {
                    row.child(formatTeamDetails(rowData)).show();
                    tr.addClass('shown');
                    icon.removeClass('fa-plus-circle text-success').addClass('fa-minus-circle text-danger');
                    if(rowData && rowData.team_leader_username) {
                        localStorage.setItem('expandedTeam_SubTL', rowData.team_leader_username); // Open panna memory la save pandrom
                    }
                }
            });
            
            // Style rows on hover
            $('#teamTable tbody').on('mouseenter', 'tr', function() {
                if (!$(this).hasClass('shown')) {
                    $(this).find('.expand-icon i').addClass('fa-beat');
                }
            }).on('mouseleave', 'tr', function() {
                $(this).find('.expand-icon i').removeClass('fa-beat');
            });

            // Add refresh button functionality
            $('.btn-refresh').on('click', function() {
                var btn = $(this);
                btn.prop('disabled', true).html('<i class="fas fa-spinner fa-spin me-2"></i>Refreshing...');
                
                setTimeout(function() {
                    location.reload();
                }, 1000);
            });
        });
    </script>
</body>
</html>
