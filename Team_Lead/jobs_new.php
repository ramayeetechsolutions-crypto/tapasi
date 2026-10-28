<?php
// Start a session if one is not already active
if (session_status() === PHP_SESSION_NONE) {
    session_start();
    // Get the user ID from the session if it's set
    if(isset($_SESSION['uid'])) $exeid=$_SESSION['uid'];
}

// Include the database connection file
require 'db.php';

// Helper function to escape HTML special characters for security
function h($s) {
    return htmlspecialchars((string)$s, ENT_QUOTES);
}

// Function to normalize a process name string
// It trims whitespace, converts to uppercase, splits by '+', sorts, and re-joins
function normalize_process($p){
    if(!$p) return '';
    $parts = array_map('trim', explode('+', strtoupper(str_replace(' ', '', $p))));
    sort($parts);
    return implode('+', $parts);
}

// Checks if an employee can handle a specific job stage based on their assigned skills
function canDoStage($empStages,$stage,$hier){
    foreach($empStages as $es){
        if(isset($hier[$es]) && in_array($stage,$hier[$es])) return true;
    }
    return false;
}

// Redirects the user to a new URL, optionally with query parameters
function redirect_to($params = []) {
    $url = 'jobs.php';
    if (!empty($params)) {
        $url .= '?' . http_build_query($params);
    }
    header("Location: $url");
    exit;
}

// Formats a date string into a more readable format (e.g., d-m-Y)
function formatDate($date, $format = 'd-m-Y') {
    if (!$date) return '';
    $dateObj = DateTime::createFromFormat('Y-m-d', $date);
    return $dateObj ? $dateObj->format($format) : $date;
}

// Maps normalized process names to their corresponding job stages
$processStageMap = [
    'CE'          => ['CE','LE'],
    'PAG'         => ['PAG','QC'],
    'CE+PAG'      => ['CE','LE','PAG','QC'],
    'CE+PAG+XML'  => ['CE','LE','PAG','QC','XML'],
    'CE+XML'      => ['CE','LE','XML'],
    'PAG+XML'     => ['PAG','QC','XML'],
    'XML'         => ['XML'],
];

// Defines the hierarchy of skills, e.g., a CE can also do LE tasks
$skillHierarchy = [
    'CE'  => ['CE','LE'],
    'PAG' => ['PAG','QC'],
    'XML' => ['XML'],
];

// Get logged-in user's details from the session
$logged_user = $_SESSION['username'] ?? '';
$role_name = $_SESSION['role_name'] ?? '';

// Get search, sort, and direction parameters from the URL
$search_client = trim($_GET['search_client'] ?? '');
$sort = $_GET['sort'] ?? 'job_id';
$dir = strtoupper($_GET['dir'] ?? 'DESC');
if (!in_array($dir, ['ASC', 'DESC'], true)) $dir = 'DESC';

// Define allowed sortable columns
$allowed_sorts = ['job_id', 'job_date', 'client_name', 'job_name', 'article', 'assigned_to', 'due_date'];
if (!in_array($sort, $allowed_sorts, true)) $sort = 'job_id';

// Set a default due date for new jobs
$default_due_date = date('Y-m-d H:i:s', strtotime('+5 days'));

// Load all processes from the database into an array
$all_processes = [];
$res = $mysqli->query("SELECT job_id, job_name FROM job_master WHERE status='Active' ORDER BY job_name");
if (!$res) die('Error loading processes: ' . $mysqli->error);
while ($r = $res->fetch_assoc()) $all_processes[$r['job_id']] = $r['job_name'];

// Determine stages and jobs accessible to an Executive based on their assigned processes
$exec_stages = [];
if ($role_name === 'Executive') {
    // Fetch the executive's assigned processes from the employee_master table
    $stmt = $mysqli->prepare("SELECT process, process1 FROM employee_master WHERE employee_username = ? AND status = 'Active' LIMIT 1");
    $stmt->bind_param("s", $logged_user);
    $stmt->execute();
    $stmt->bind_result($exec_process, $exec_process1);
    $stmt->fetch();
    $stmt->close();

    // Combine and normalize the processes into a list of stages
    $processList = [];
    foreach ([$exec_process, $exec_process1] as $proc) {
        if ($proc) {
            if (is_numeric($proc)) {
                $processList[] = $all_processes[$proc] ?? '';
            } else {
                $processList[] = $proc;
            }
        }
    }
    $exec_process_combined = implode('+', array_filter($processList));
    $exec_stages = explode('+', str_replace(' ', '', strtoupper($exec_process_combined)));
}

// Map stage names to the corresponding database column names for assignments
$assigned_fields_map = [
    'CE' => 'assigned_ce',
    'LE' => 'assigned_le',
    'PAG' => 'assigned_pag',
    'QC' => 'assigned_qc',
    'XML' => 'assigned_xml',
];

// --- Process Filtering for Executive Role ---
$processes = [];
$exec_process_ids = [];
if ($role_name === 'Executive') {
    // Logic to determine which processes an executive can see and assign to
    $exec_upper = normalize_process($exec_process_combined);
    
    // A mapping from an executive's combined processes to the full processes they can see
    $executiveProcessMap = [
        'CE+PAG'   => ['CE', 'PAG', 'CE+PAG', 'CE+PAG+XML'],
        'CE'       => ['CE', 'CE+PAG', 'CE+PAG+XML', 'CE+XML'], 
        'PAG'      => ['PAG', 'CE+PAG', 'CE+PAG+XML', 'PAG+XML'],
        'XML'      => ['XML', 'CE+PAG+XML', 'CE+XML', 'PAG+XML'],
        'QC'       => ['PAG', 'CE+PAG', 'CE+PAG+XML', 'PAG+XML'],
        'LE'       => ['CE', 'PAG', 'CE+PAG', 'CE+PAG+XML']
    ];

    $allowedProcessNames = $executiveProcessMap[$exec_upper] ?? [];
    
    // Fallback logic if the combined process is not in the map
    if (empty($allowedProcessNames)) {
        foreach ($all_processes as $pid => $pname) {
            $process_stages = $processStageMap[normalize_process($pname)] ?? [];
            foreach ($exec_stages as $exec_stage) {
                if (in_array($exec_stage, $process_stages, true)) {
                    $allowedProcessNames[] = $pname;
                    break;
                }
            }
        }
    }
    
    // Populate the final list of processes visible to the executive
    foreach ($all_processes as $pid => $pname) {
        if (in_array($pname, $allowedProcessNames, true)) {
            $exec_process_ids[] = $pid;
            $processes[$pid] = $pname;
        }
    }
    
    // If no processes are matched, show all as a final fallback
    if (empty($processes)) {
        $processes = $all_processes;
    }

} else {
    // Team Lead and Admin can see all processes
    $processes = $all_processes;
}

// Get all clients for the dropdown menu
$clients_res = $mysqli->query("SELECT client_id, client_name FROM client_master WHERE status='Active' ORDER BY client_name");
if (!$clients_res) die('Error fetching clients: ' . $mysqli->error);

// Helper function to get a role ID from the database
function getRoleId($mysqli, $name) {
    $st = $mysqli->prepare("SELECT role_id FROM user_role_master WHERE role_name=? AND status='Active' LIMIT 1");
    if (!$st) die('Error preparing role query: ' . $mysqli->error);
    $st->bind_param("s", $name);
    $st->execute();
    $res = $st->get_result();
    $id = $res->num_rows ? $res->fetch_assoc()['role_id'] : 0;
    $st->close();
    return $id;
}

// Get the role IDs for Executive and Team Lead
$roleExec = getRoleId($mysqli, 'Executive');
$roleTeamLead = getRoleId($mysqli, 'Team Lead');

// Load all active employees and their process/stage information for assignment dropdowns
$st = $mysqli->prepare("SELECT employee_id, employee_username, employee_name, user_role_id, COALESCE(jm.job_name, em.process) AS process
                        FROM employee_master em
                        LEFT JOIN job_master jm ON em.process = jm.job_id
                        WHERE em.status='Active'");
if (!$st) die('Error preparing employee query: ' . $mysqli->error);
$st->execute();
$resEmps = $st->get_result();
if (!$resEmps) die('Error executing employee query: ' . $st->error);
$employees = [];
while ($e = $resEmps->fetch_assoc()) {
    $procNorm = normalize_process($e['process']);
    $stages = array_filter(explode('+', $procNorm));
    $e['label'] = $e['employee_name'] . ' (' . $e['process'] . ')';
    $e['stages'] = $stages;
    $employees[] = $e;
}
$st->close();

// Load team allotments for the Team Lead role
$teamAllotments = [];
if ($role_name === 'Team Lead') {
    $teamLeadId = null;
    $stmt = $mysqli->prepare("SELECT employee_id FROM employee_master WHERE employee_username = ? AND status = 'Active' LIMIT 1");
    if ($stmt) {
        $stmt->bind_param('s', $logged_user);
        $stmt->execute();
        $stmt->bind_result($teamLeadId);
        $stmt->fetch();
        $stmt->close();
        
        if ($teamLeadId) {
            // Fetch the team allotment data with employee names
            $sql = "SELECT ta.*, jm.job_name,
                           ce.employee_username AS ce_username, ce.employee_name AS ce_name,
                           le.employee_username AS le_username, le.employee_name AS le_name,
                           pag.employee_username AS pag_username, pag.employee_name AS pag_name,
                           qc.employee_username AS qc_username, qc.employee_name AS qc_name,
                           xml.employee_username AS xml_username, xml.employee_name AS xml_name
                    FROM team_allotment ta
                    JOIN job_master jm ON ta.job_id = jm.job_id
                    LEFT JOIN employee_master ce ON ta.ce_id = ce.employee_id
                    LEFT JOIN employee_master le ON ta.le_id = le.employee_id
                    LEFT JOIN employee_master pag ON ta.pag_id = pag.employee_id
                    LEFT JOIN employee_master qc ON ta.qc_id = qc.employee_id
                    LEFT JOIN employee_master xml ON ta.xml_id = xml.employee_id
                    WHERE ta.team_leader_id = ?";
            
            $stmt = $mysqli->prepare($sql);
            if ($stmt) {
                $stmt->bind_param('i', $teamLeadId);
                $stmt->execute();
                $res = $stmt->get_result();
                while ($row = $res->fetch_assoc()) {
                    $teamAllotments[$row['job_id']] = $row;
                }
                $stmt->close();
            }
        }
    }
}

// --- Handle POST requests for form submissions ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $job_id = (int)($_POST['job_id'] ?? 0);
    $job_date = $_POST['job_date'] ?: date('Y-m-d');
    $client_id = (int)($_POST['client_id'] ?? 0);
    $journal = trim($_POST['journal_issue'] ?? '');
    $article = trim($_POST['article'] ?? '');
    $process_id = (int)($_POST['process_id'] ?? 0);
   // $due_date = $_POST['due_date'] ?: $default_due_date;
    $due_date = $default_due_date;
    $due_date_ce = date('Y-m-d H:i:s', strtotime('+1 days'));
    $due_date_le = date('Y-m-d H:i:s', strtotime('+2 days'));
    $due_date_pag = date('Y-m-d H:i:s', strtotime('+3 days'));
    $due_date_qc = date('Y-m-d H:i:s', strtotime('+4 days'));
    $use_team = $_POST['use_team'] ?? 'no';

    // Check for duplicate journal/article entries
    $checkSql = "SELECT COUNT(*) FROM jobs WHERE journal_issue = ? AND article = ?";
    if ($action === 'edit') {
        $checkSql .= " AND job_id != ?";
    }
    $stmtC = $mysqli->prepare($checkSql);
    if (!$stmtC) die('Error preparing uniqueness check: ' . $mysqli->error);
    if ($action === 'edit') {
        $stmtC->bind_param('ssi', $journal, $article, $job_id);
    } else {
        $stmtC->bind_param('ss', $journal, $article);
    }
    $stmtC->execute();
    $stmtC->bind_result($dupCount);
    $stmtC->fetch();
    $stmtC->close();
    
    if ($dupCount > 0) {
        redirect_to(['msg' => 'Journal and Article combination already exists.', 'type' => 'danger']);
    }

    if ($action === 'add') {
        // Validation for required fields
        if (!$client_id || !$journal || !$article || !$process_id) {
            redirect_to(['msg' => 'Please fill all required fields', 'type' => 'danger']);
        }

        // Initialize assignment variables
        $assigned_to = null;
        $assigned_ce = null;
        $assigned_le = null;
        $assigned_pag = null;
        $assigned_qc = null;
        $assigned_xml = null;
        
  /*      if ($role_name === 'Executive') {
            // Executive: Auto-assigns to themselves for stages they handle
            $job_process_name = $all_processes[$process_id] ?? '';
            $job_stages = $processStageMap[normalize_process($job_process_name)] ?? [];
            
            // Check if executive is authorized for this job's process
            $can_contribute = false;
            foreach ($exec_stages as $exec_stage) {
                if (in_array($exec_stage, $job_stages, true)) {
                    $can_contribute = true;
                    break;
                }
            }
            if (!$can_contribute) {
                redirect_to(['msg' => 'You are not authorized for this process', 'type' => 'danger']);
            }
            
            $assigned_to = $logged_user;
            
            // Assign the executive to the relevant stage fields
            foreach ($exec_stages as $exec_stage) {
                if (in_array($exec_stage, $job_stages, true)) {
                    $stage_var = 'assigned_' . strtolower($exec_stage);
                    $$stage_var = $logged_user;
                }
            }
            
        } else if ($role_name === 'Team Lead') {
            // Team Lead: Can use a team allotment or manually assign executives
            if ($use_team === 'yes' && isset($teamAllotments[$process_id])) {
                // Assign based on the team allotment
                $team = $teamAllotments[$process_id];
                $job_process_name = $all_processes[$process_id] ?? '';
                $job_stages = $processStageMap[normalize_process($job_process_name)] ?? [];
                
                foreach ($job_stages as $stage) {
                    $stage_field = strtolower($stage) . '_username';
                    if (!empty($team[$stage_field])) {
                        $stage_var = 'assigned_' . strtolower($stage);
                        $$stage_var = $team[$stage_field];
                        if (!$assigned_to) {
                            $assigned_to = $team[$stage_field];
                        }
                    }
                }
                
                if (!$assigned_to) {
                    redirect_to(['msg' => 'No executive found in team for this process', 'type' => 'danger']);
                }
            } else {
                // Manual assignment via the form dropdowns
                $job_process_name = $all_processes[$process_id] ?? '';
                $job_stages = $processStageMap[normalize_process($job_process_name)] ?? [];
                
                foreach ($job_stages as $stage) {
                    $stage_exec = $_POST['exec_' . strtolower($stage)] ?? '';
                    if ($stage_exec) {
                        $stage_var = 'assigned_' . strtolower($stage);
                        $$stage_var = $stage_exec;
                        if (!$assigned_to) {
                            $assigned_to = $stage_exec;
                        }
                    }
                }
                
                if (!$assigned_to) {
                    redirect_to(['msg' => 'Please assign executives for at least one stage', 'type' => 'danger']);
                }
            }
        } else {
            // Admin: Assigns a single executive
            $assigned_to = $_POST['assigned_to'] ?? '';
            if (!$assigned_to) {
                redirect_to(['msg' => 'Please assign executive', 'type' => 'danger']);
            }
        }*/
$uid = $_SESSION['uid']; // employee_id of logged-in user
//echo "uid".$uid;
//die;
$sql = "
    SELECT tl.employee_name AS team_lead_name, tl.employee_id AS team_lead_id
    FROM team_allotment t
    JOIN employee_master e ON e.employee_id = ? 
    JOIN employee_master tl ON t.team_lead = tl.employee_id
    WHERE ? IN (
        t.front_matter_CE, t.content_CE, t.equation_CE, t.table_CE,
        t.image_CE, t.ref, t.fqa_CE, t.word_process,
        t.table_pag, t.idm, t.paginator, t.xml
    )
";

$stmt = $mysqli->prepare($sql);
if (!$stmt) die("Error preparing: " . $mysqli->error);

$stmt->bind_param("ii", $uid, $uid);
$stmt->execute();
$res = $stmt->get_result();

if ($row = $res->fetch_assoc()) {
  //  echo "Team Lead: " . $row['team_lead_name'];
  $assigned_to=$row['team_lead_name'];
} else {
    echo "No Team Lead found for this employee.";
}

$stmt->close();
     
// jobs insert (same as your code)
$stmt = $mysqli->prepare("
    INSERT INTO jobs (
        job_date, client_id, journal_issue, article, process_id,
        assigned_to, assigned_ce, assigned_le, assigned_pag,
        assigned_qc, assigned_xml, due_date, due_date_ce,
        due_date_le, due_date_pag, due_date_qc
    )
    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
");
if (!$stmt) die('Error preparing jobs insert: ' . $mysqli->error);

$stmt->bind_param(
    'sissssssssssssss',
    $job_date,
    $client_id,
    $journal,
    $article,
    $process_id,
    $assigned_to,
    $assigned_ce,
    $assigned_le,
    $assigned_pag,
    $assigned_qc,
    $assigned_xml,
    $due_date,
    $due_date_ce,
    $due_date_le,
    $due_date_pag,
    $due_date_qc
);

if (!$stmt->execute()) die('Jobs insert failed: ' . $stmt->error);
$stmt->close();

$job_id = $mysqli->insert_id;

// -------------------------------------------------
// Get client name
// -------------------------------------------------
$client_name = '';
$stmt = $mysqli->prepare("SELECT client_name FROM client_master WHERE client_id = ?");
$stmt->bind_param("i", $client_id);
$stmt->execute();
$stmt->bind_result($client_name);
$stmt->fetch();
$stmt->close();

// -------------------------------------------------
// Get process name
// -------------------------------------------------
$process_name = '';
$stmt = $mysqli->prepare("SELECT job_name FROM job_master WHERE job_id = ?");
$stmt->bind_param("i", $process_id);
$stmt->execute();
$stmt->bind_result($process_name);
$stmt->fetch();
$stmt->close();

// -------------------------------------------------
// Insert CE and LE if process_id in [1,4,5,37]
// -------------------------------------------------
if (in_array($process_id, [1, 4, 5, 37])) {
    // CE
    $stmt = $mysqli->prepare("
        INSERT INTO ce (job_id, date, client, issue, articles, process, status, created_at, last_updated)
        VALUES (?, ?, ?, ?, ?, ?, 'Need to Assign', NOW(), NOW())
    ");
    $stmt->bind_param("isssss", $job_id, $job_date, $client_name, $journal, $article, $process_name);
    $stmt->execute();
    $stmt->close();

    // LE
    $stmt = $mysqli->prepare("
        INSERT INTO le (job_id, date, client, issue, articles, process, status, created_at, last_updated)
        VALUES (?, ?, ?, ?, ?, ?, 'Need to Assign', NOW(), NOW())
    ");
    $stmt->bind_param("isssss", $job_id, $job_date, $client_name, $journal, $article, $process_name);
    $stmt->execute();
    $stmt->close();
}

// -------------------------------------------------
// Insert PAG and QC if process_id in [2,4,5]
// -------------------------------------------------
if (in_array($process_id, [2, 4, 5])) {
    // PAG
    $stmt = $mysqli->prepare("
        INSERT INTO pag (job_id, date, client, issue, articles, process, status, created_at, last_updated)
        VALUES (?, ?, ?, ?, ?, ?, 'Need to Assign', NOW(), NOW())
    ");
    $stmt->bind_param("isssss", $job_id, $job_date, $client_name, $journal, $article, $process_name);
    $stmt->execute();
    $stmt->close();

    // QC
   $stmt = $mysqli->prepare("
    INSERT INTO qc 
    (job_id, date, client, journal_issue, article, process, status, created_at, last_updated)
    VALUES (?, ?, ?, ?, ?, ?, 'Need to Assign', NOW(), NOW())
");
$stmt->bind_param("isssss", $job_id, $job_date, $client_name, $journal, $article, $process_name);
$stmt->execute();
$stmt->close();
}

// -------------------------------------------------
// Redirect or Success message
// -------------------------------------------------
redirect_to(['msg' => 'Job added successfully', 'type' => 'success']);
}
elseif ($action === 'edit' && in_array($role_name, ['Team Lead', 'Admin'], true)) {
        // Edit logic, similar to add but updates an existing record
        $assigned_to = null;
        $assigned_ce = null;
        $assigned_le = null;
        $assigned_pag = null;
        $assigned_qc = null;
        $assigned_xml = null;
        
        if ($role_name === 'Team Lead') {
            if ($use_team === 'yes' && isset($teamAllotments[$process_id])) {
                $team = $teamAllotments[$process_id];
                $job_process_name = $all_processes[$process_id] ?? '';
                $job_stages = $processStageMap[normalize_process($job_process_name)] ?? [];
                
                foreach ($job_stages as $stage) {
                    $stage_field = strtolower($stage) . '_username';
                    if (!empty($team[$stage_field])) {
                        $stage_var = 'assigned_' . strtolower($stage);
                        $$stage_var = $team[$stage_field];
                        if (!$assigned_to) {
                            $assigned_to = $team[$stage_field];
                        }
                    }
                }
                
                if (!$assigned_to) {
                    redirect_to(['msg' => 'No executive found in team for this process', 'type' => 'danger']);
                }
            } else {
                $job_process_name = $all_processes[$process_id] ?? '';
                $job_stages = $processStageMap[normalize_process($job_process_name)] ?? [];
                
                foreach ($job_stages as $stage) {
                    $stage_exec = $_POST['exec_' . strtolower($stage)] ?? '';
                    if ($stage_exec) {
                        $stage_var = 'assigned_' . strtolower($stage);
                        $$stage_var = $stage_exec;
                        if (!$assigned_to) {
                            $assigned_to = $stage_exec;
                        }
                    }
                }
                
                if (!$assigned_to) {
                    redirect_to(['msg' => 'Please assign executives for at least one stage', 'type' => 'danger']);
                }
            }
        } else {
            $assigned_to = $_POST['assigned_to'] ?? '';
            if (!$assigned_to) {
                redirect_to(['msg' => 'Please assign executive', 'type' => 'danger']);
            }
        }
        
        // Update the job record in the database
        $stmt = $mysqli->prepare("UPDATE jobs SET job_date=?, client_id=?, journal_issue=?, article=?, process_id=?, assigned_to=?, assigned_ce=?, assigned_le=?, assigned_pag=?, assigned_qc=?, assigned_xml=?, due_date=? WHERE job_id=?");
        if (!$stmt) die('Error preparing update: ' . $mysqli->error);
        $stmt->bind_param('sissssssssssi', $job_date, $client_id, $journal, $article, $process_id, $assigned_to, $assigned_ce, $assigned_le, $assigned_pag, $assigned_qc, $assigned_xml, $due_date, $job_id);
        if (!$stmt->execute()) {
            die('Update failed: ' . $stmt->error);
        }
        $stmt->close();
        
        redirect_to(['msg' => 'Job updated successfully', 'type' => 'success']);

    } elseif ($action === 'delete' && in_array($role_name, ['Team Lead', 'Admin'], true)) {
        // Delete a job record
        $stmt = $mysqli->prepare("DELETE FROM jobs WHERE job_id = ?");
        if (!$stmt) die('Error preparing delete: ' . $mysqli->error);
        $stmt->bind_param('i', $job_id);
        if (!$stmt->execute()) {
            die('Deletion failed: ' . $stmt->error);
        }
        $stmt->close();
        redirect_to(['msg' => 'Job deleted', 'type' => 'warning']);
    }
}

// --- Main query to fetch job list based on role and filters ---
$where_clauses = [];
$params = [];
$types = '';

// Role-based filtering
if ($role_name === 'Executive' && !empty($exec_process_ids)) {
    // Filter by process_id first
    $process_placeholders = implode(',', array_fill(0, count($exec_process_ids), '?'));
    $where_clauses[] = "j.process_id IN ($process_placeholders)";
    $params = array_merge($params, $exec_process_ids);
    $types .= str_repeat('i', count($exec_process_ids));

    // Then, filter by assigned stages
    $stage_conditions = [];
    foreach ($exec_stages as $stage) {
        if (isset($assigned_fields_map[$stage])) {
            $field = $assigned_fields_map[$stage];
            $stage_conditions[] = "($field IS NULL OR $field = ?)";
            $params[] = $logged_user;
            $types .= 's';
        }
    }
    if ($stage_conditions) {
        $where_clauses[] = '(' . implode(' OR ', $stage_conditions) . ')';
    } else {
        $where_clauses[] = '0=1'; // No stages means no jobs are visible
    }
} elseif ($role_name === 'Team Lead') {
    // Team Lead sees all jobs (no filtering needed here)
}

// Optional client name search filter
if (!empty($search_client)) {
    $where_clauses[] = "c.client_name LIKE ?";
    $params[] = "%$search_client%";
    $types .= 's';
}

// Build the final WHERE clause string
$where_sql = '';
if ($where_clauses) {
    $where_sql = 'WHERE ' . implode(' AND ', $where_clauses);
}

// The main SQL query with joins to get client and process names
$sql = "SELECT j.job_id, j.job_date, j.journal_issue, j.article, j.assigned_to, j.assigned_ce, j.assigned_le, j.assigned_pag, j.assigned_qc, j.assigned_xml, j.due_date, j.process_id, c.client_name, p.job_name
        FROM jobs j
        JOIN client_master c ON j.client_id = c.client_id
        JOIN job_master p ON j.process_id = p.job_id
        $where_sql
        ORDER BY $sort $dir";

// Prepare and execute the query, binding parameters if necessary
if ($where_sql && $types) {
    $stmt = $mysqli->prepare($sql);
    if (!$stmt) die('Error preparing jobs query: ' . $mysqli->error);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $jobs_res = $stmt->get_result();
    $stmt->close();
} else {
    $jobs_res = $mysqli->query($sql);
    if (!$jobs_res) die('Error executing jobs query: ' . $mysqli->error);
}

// Check for edit mode and load job data if requested
$mode = $_GET['mode'] ?? '';
$edit_id = (int)($_GET['id'] ?? 0);
$edit_job = null;

if ($mode === 'edit' && in_array($role_name, ['Team Lead', 'Admin'], true) && $edit_id > 0) {
    $stmt = $mysqli->prepare("SELECT * FROM jobs WHERE job_id = ? LIMIT 1");
    if (!$stmt) die('Error preparing edit query: ' . $mysqli->error);
    $stmt->bind_param('i', $edit_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $edit_job = $result->fetch_assoc();
    $stmt->close();
}

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <title>Jobs - Tapasi ERP</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet" />
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet" />
    <style>
        .btn-brown { background-color: brown !important; color: white !important; }
        .compact-form { max-height: 80vh; overflow-y: auto; }
        .assignment-popup { max-width: 400px; }
        .executive-compact { max-width: 500px; }
        .text-warning { color: #f39c12 !important; }
        .text-success { color: #27ae60 !important; }
    </style>
</head>
<body>
    <?php include 'header.php'; ?>

    <div class="container mt-3">

        <!-- Display status messages from the redirect_to function -->
        <?php if (!empty($_GET['msg'])): ?>
            <div class="alert alert-<?= h($_GET['type'] ?? 'info') ?> alert-dismissible fade show">
                <?= h($_GET['msg']) ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <!-- Form for adding/editing jobs -->
        <?php if ($mode === 'add' || $mode === 'edit'): ?>

            <div class="d-flex justify-content-between align-items-center mb-3">
                <h5><?= $mode === 'edit' ? 'Edit' : 'Add' ?> Job</h5>
                <a href="jobs.php" class="btn btn-sm btn-secondary">Back</a>
            </div>

            <div class="card mx-auto <?= $role_name === 'Executive' ? 'executive-compact' : 'compact-form' ?>" style="max-width:<?= $role_name === 'Executive' ? '500px' : '700px' ?>;">
                <div class="card-body">
                    <form method="post" autocomplete="off">
                        <input type="hidden" name="action" value="<?= $mode ?>" />
                        <?php if ($mode === 'edit'): ?>
                            <input type="hidden" name="job_id" value="<?= (int)$edit_job['job_id'] ?>" />
                        <?php endif; ?>

                        <!-- Executive form layout (simplified) -->
                        <?php if ($role_name === 'Executive'): ?>
                            <div class="row g-2 mb-2">
                                <div class="col-6">
                                    <label class="form-label small">Job Date</label>
                                    <input type="date" name="job_date" class="form-control form-control-sm" required value="<?= h($mode === 'edit' ? $edit_job['job_date'] : date('Y-m-d')) ?>" />
                                </div>
                                <!--div class="col-6">
                                    <label class="form-label small">Due Date</label>
                                    <input type="date" name="due_date" class="form-control form-control-sm" value="<?= h($mode === 'edit' ? $edit_job['due_date'] : $default_due_date) ?>" />
                                </div-->
                            </div>
                            
                            <div class="mb-2">
                                <label class="form-label small">Client *</label>
                                <select name="client_id" class="form-select form-select-sm" required>
                                    <option value="">Select Client</option>
                                    <?php $clients_res->data_seek(0); while ($client = $clients_res->fetch_assoc()): ?>
                                        <option value="<?= $client['client_id'] ?>" <?= ($mode === 'edit' && $edit_job['client_id'] == $client['client_id']) ? 'selected' : '' ?>><?= h($client['client_name']) ?></option>
                                    <?php endwhile; ?>
                                </select>
                            </div>

                            <div class="row g-2 mb-2">
                                <div class="col-6">
                                    <label class="form-label small">Journal/Issue *</label>
                                    <input type="text" name="journal_issue" class="form-control form-control-sm" required value="<?= h($mode === 'edit' ? $edit_job['journal_issue'] : '') ?>" />
                                </div>
                                <div class="col-6">
                                    <label class="form-label small">Article *</label>
                                    <input type="text" name="article" class="form-control form-control-sm" required value="<?= h($mode === 'edit' ? $edit_job['article'] : '') ?>" />
                                </div>
                            </div>

                            <div class="mb-2">
                                <label class="form-label small">Process *</label>
                                <select name="process_id" class="form-select form-select-sm" required>
                                    <option value="">Select Process</option>
                                    <?php foreach ($processes as $pid => $pname): ?>
                                        <option value="<?= $pid ?>" <?= ($mode === 'edit' && $edit_job['process_id'] == $pid) ? 'selected' : '' ?>><?= h($pname) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <!--div class="mb-2">
                                <label class="form-label small">Assigned To</label>
                                <input type="text" class="form-control form-control-sm" value="<?= h($logged_user) ?>" readonly />
                                <input type="hidden" name="assigned_to" value="<?= h($logged_user) ?>" />
                                <small class="text-muted">Auto-assigned to your stages: <?= h(implode(', ', $exec_stages)) ?></small>
                            </div-->

                        <!-- Team Lead/Admin form layout -->
                        <?php else: ?>
                            <div class="row g-2 mb-2">
                                <div class="col-md-4">
                                    <label class="form-label small">Job Date</label>
                                    <input type="date" name="job_date" class="form-control form-control-sm" required value="<?= h($mode === 'edit' ? $edit_job['job_date'] : date('Y-m-d')) ?>" />
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label small">Due Date</label>
                                    <input type="date" name="due_date" class="form-control form-control-sm" value="<?= h($mode === 'edit' ? $edit_job['due_date'] : $default_due_date) ?>" />
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label small">Client *</label>
                                    <select name="client_id" class="form-select form-select-sm" required>
                                        <option value="">Select Client</option>
                                        <?php $clients_res->data_seek(0); while ($client = $clients_res->fetch_assoc()): ?>
                                            <option value="<?= $client['client_id'] ?>" <?= ($mode === 'edit' && $edit_job['client_id'] == $client['client_id']) ? 'selected' : '' ?>><?= h($client['client_name']) ?></option>
                                        <?php endwhile; ?>
                                    </select>
                                </div>
                            </div>

                            <div class="row g-2 mb-2">
                                <div class="col-md-6">
                                    <label class="form-label small">Journal / Issue *</label>
                                    <input type="text" name="journal_issue" class="form-control form-control-sm" required value="<?= h($mode === 'edit' ? $edit_job['journal_issue'] : '') ?>" />
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label small">Article *</label>
                                    <input type="text" name="article" class="form-control form-control-sm" required value="<?= h($mode === 'edit' ? $edit_job['article'] : '') ?>" />
                                </div>
                            </div>

                            <div class="mb-2">
                                <label class="form-label small">Process *</label>
                                <select name="process_id" id="process_id" class="form-select form-select-sm" required onchange="processChanged(this.value)">
                                    <option value="">Select Process</option>
                                    <?php foreach ($processes as $pid => $pname): ?>
                                        <option value="<?= $pid ?>" <?= ($mode === 'edit' && $edit_job['process_id'] == $pid) ? 'selected' : '' ?>><?= h($pname) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <!-- Team Lead assignment options (manual or team) -->
                            <?php if ($role_name === 'Team Lead'): ?>
                                <div id="assignment-options" style="display:none;">
                                    <div class="mb-2">
                                        <label class="form-label small">Assignment Method</label>
                                        <div class="form-check">
                                            <input class="form-check-input" type="radio" name="use_team" value="yes" id="use_team_yes" onchange="toggleAssignmentMethod()">
                                            <label class="form-check-label small" for="use_team_yes">Use Team Allotment</label>
                                        </div>
                                        <div class="form-check">
                                            <input class="form-check-input" type="radio" name="use_team" value="no" id="use_team_no" checked onchange="toggleAssignmentMethod()">
                                            <label class="form-check-label small" for="use_team_no">Manual Assignment</label>
                                        </div>
                                    </div>
                                    
                                    <div id="team-assignment-info" style="display:none;" class="alert alert-info p-2 small">
                                        <strong>Team Assignment:</strong>
                                        <div id="team-details"></div>
                                    </div>
                                    
                                    <div id="manual-assignment">
                                        <div id="exec-stage-selects" class="row g-2">
                                            <!-- Dynamic executive assignment dropdowns will be inserted here -->
                                        </div>
                                    </div>
                                </div>
                            <?php else: ?>
                                <!-- Admin assignment field -->
                                <div class="mb-2">
                                    <label class="form-label small">Assigned To *</label>
                                    <input type="text" name="assigned_to" class="form-control form-control-sm" required value="<?= h($mode === 'edit' ? $edit_job['assigned_to'] : '') ?>" />
                                </div>
                            <?php endif; ?>
                        <?php endif; ?>

                        <div class="d-flex gap-2 pt-2">
                            <button type="submit" class="btn btn-sm btn-brown"><?= $mode === 'edit' ? 'Update' : 'Save' ?></button>
                            <a href="jobs.php" class="btn btn-sm btn-outline-secondary">Cancel</a>
                        </div>
                    </form>
                </div>
            </div>

            <!-- JavaScript for dynamic form behavior -->
            <script>
                // Pass PHP data to JavaScript for dynamic form generation
                const processStageMap = <?= json_encode($processStageMap) ?>;
                const skillHierarchy = <?= json_encode($skillHierarchy) ?>;
                const employees = <?= json_encode($employees) ?>;
                const roleExec = <?= json_encode($roleExec) ?>;
                const teamAllotments = <?= json_encode($teamAllotments) ?>;
                const allProcesses = <?= json_encode($all_processes) ?>;
                const editJob = <?= json_encode($edit_job) ?>;

                // Function to check if an employee can handle a stage
                function canDoStage(empStages, stage, hier) {
                    for (let es of empStages) {
                        if (hier[es] && hier[es].includes(stage)) return true;
                    }
                    return false;
                }

                // Function to normalize a process name string
                function normalizeProcess(p) {
                    if (!p) return '';
                    let parts = p.toUpperCase().replace(/\s+/g, '').split('+');
                    parts.sort();
                    return parts.join('+');
                }

                // Handler for when the process dropdown changes
                function processChanged(selectedProcessId) {
                    let assignmentOptions = document.getElementById('assignment-options');
                    if (assignmentOptions) {
                        if (selectedProcessId) {
                            assignmentOptions.style.display = 'block';
                            updateTeamInfo(selectedProcessId);
                            updateManualAssignment(selectedProcessId);
                        } else {
                            assignmentOptions.style.display = 'none';
                        }
                    }
                }

                // Updates the display with team allotment information
                function updateTeamInfo(processId) {
                    let teamDetails = document.getElementById('team-details');
                    let teamInfo = teamAllotments[processId];
                    
                    if (teamInfo) {
                        let processName = allProcesses[processId];
                        let normProc = normalizeProcess(processName);
                        let stages = processStageMap[normProc] || [];
                        
                        let html = '';
                        stages.forEach(stage => {
                            let field = stage.toLowerCase() + '_name';
                            let name = teamInfo[field] || 'Not assigned';
                            html += `<small><strong>${stage}:</strong> ${name}</small><br>`;
                        });
                        teamDetails.innerHTML = html;
                    } else {
                        teamDetails.innerHTML = '<small class="text-warning">No team allotment found for this process</small>';
                    }
                }

                // Dynamically creates the dropdowns for manual assignment
                function updateManualAssignment(processId) {
                    let container = document.getElementById('exec-stage-selects');
                    container.innerHTML = '';
                    
                    let processName = allProcesses[processId];
                    if (!processName) return;

                    let normProc = normalizeProcess(processName);
                    let stages = processStageMap[normProc] || [];

                    stages.forEach(stage => {
                        let colClass = stages.length <= 2 ? 'col-md-6' : (stages.length <= 3 ? 'col-md-4' : 'col-md-3');
                        
                        let div = document.createElement('div');
                        div.className = colClass + ' mb-2';
                        
                        let label = document.createElement('label');
                        label.className = 'form-label small';
                        label.textContent = stage + ' *';
                        
                        let select = document.createElement('select');
                        select.name = 'exec_' + stage.toLowerCase();
                        select.className = 'form-select form-select-sm';
                        select.required = true;
                        
                        let option = document.createElement('option');
                        option.value = '';
                        option.textContent = 'Select ' + stage;
                        select.appendChild(option);

                        let currentAssignment = '';
                        if (editJob) {
                            currentAssignment = editJob['assigned_' + stage.toLowerCase()] || '';
                        }

                        employees.forEach(emp => {
                            if (emp.user_role_id === roleExec && canDoStage(emp.stages, stage, skillHierarchy)) {
                                let optEmp = document.createElement('option');
                                optEmp.value = emp.employee_username;
                                optEmp.textContent = emp.label;
                                
                                if (currentAssignment === emp.employee_username) {
                                    optEmp.selected = true;
                                }
                                
                                select.appendChild(optEmp);
                            }
                        });

                        div.appendChild(label);
                        div.appendChild(select);
                        container.appendChild(div);
                    });
                }

                // Toggles between team and manual assignment methods
                function toggleAssignmentMethod() {
                    let useTeam = document.getElementById('use_team_yes').checked;
                    let teamInfo = document.getElementById('team-assignment-info');
                    let manualAssignment = document.getElementById('manual-assignment');
                    
                    if (useTeam) {
                        teamInfo.style.display = 'block';
                        manualAssignment.style.display = 'none';
                        document.querySelectorAll('#exec-stage-selects select').forEach(sel => {
                            sel.required = false;
                        });
                    } else {
                        teamInfo.style.display = 'none';
                        manualAssignment.style.display = 'block';
                        document.querySelectorAll('#exec-stage-selects select').forEach(sel => {
                            sel.required = true;
                        });
                    }
                }

                // Initialize the form on page load if in edit mode
                <?php if ($mode === 'edit' && isset($edit_job['process_id']) && $role_name === 'Team Lead'): ?>
                    processChanged(<?= (int)$edit_job['process_id'] ?>);
                <?php endif; ?>
            </script>

        <!-- Main job list display -->
        <?php else: ?>
            <div class="d-flex justify-content-between align-items-center mb-2">
                <h5>Jobs</h5>
                <a href="jobs.php?mode=add" class="btn btn-sm btn-success"><i class="fa fa-plus"></i> Add Job</a>
            </div>

            <!-- Filter form -->
            <form method="get" class="row gy-2 gx-2 mb-3 align-items-end">
                <div class="col-md-4">
                    <input type="text" name="search_client" value="<?= h($search_client) ?>" placeholder="Search by client" class="form-control form-control-sm">
                </div>
                <div class="col-md-3">
                    <select name="sort" class="form-select form-select-sm">
                        <?php foreach ($allowed_sorts as $s): ?>
                            <option value="<?= $s ?>" <?= $sort === $s ? 'selected' : '' ?>><?= ucwords(str_replace('_', ' ', $s)) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <select name="dir" class="form-select form-select-sm">
                        <option value="ASC" <?= $dir === 'ASC' ? 'selected' : '' ?>>Asc</option>
                        <option value="DESC" <?= $dir === 'DESC' ? 'selected' : '' ?>>Desc</option>
                    </select>
                </div>
                <div class="col-md-3">
                    <button type="submit" class="btn btn-primary btn-sm">Filter</button>
                    <a href="jobs.php" class="btn btn-secondary btn-sm">Clear</a>
                </div>
            </form>

            <!-- Table to display job list -->
            <div class="card">
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th>#</th><th>Date</th><th>Client</th><th>Journal/Issue</th><th>Article</th><th>Process</th><th>Job Assign</th><th>Due Date</th>
                                    <?php if (in_array($role_name, ['Team Lead', 'Admin'], true)): ?><th>Actions</th><?php endif; ?>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if ($jobs_res && $jobs_res->num_rows):
                                    $i=0;
                                    while ($job = $jobs_res->fetch_assoc()): 
                                $i=$i+1;
                                ?>
                                    <tr>
                                        <td><?= $i;//(int)$job['job_id'] ?></td>
                                        <td><?= formatDate($job['job_date']) ?></td>
                                        <td><?= h($job['client_name']) ?></td>
                                        <td><?= h($job['journal_issue']) ?></td>
                                        <td><?= h($job['article']) ?></td>
                                        <td><?= h($job['job_name']) ?></td>
                                        <td><?php
// Display a badge for each job stage with the assigned executive
$process_name = $job['job_name'];
$norm_proc = normalize_process($process_name);
$stages = $processStageMap[$norm_proc] ?? [];

// Define colors for each stage
$bg_colors = [
    'CE'  => 'bg-primary text-white',   // Blue
    'LE'  => 'bg-info text-dark',       // Light Blue
    'PAG' => 'bg-success text-white',   // Green
    'QC'  => 'bg-warning text-dark',    // Yellow
    'XML' => 'bg-danger text-white'     // Red
];

foreach ($stages as $stage):
    $stage_field = 'assigned_' . strtolower($stage);
    $stage_assigned = $job[$stage_field] ?? null;

    if ($stage_assigned) {
        $display_text = $stage . '-' . h($stage_assigned); 
        $bg_class = $bg_colors[$stage] ?? 'bg-light text-dark';
    } else {
        $display_text = $stage . '-Awaiting'; 
        $bg_class = 'bg-secondary text-white';
    }
?>
    <span class="badge <?= $bg_class ?>" style="padding:6px 10px; font-size:0.85rem;">
        <?= $display_text ?>
    </span>
<?php endforeach; ?>
</td>
                                        <td><?= formatDate($job['due_date']) ?></td>
                                        <!-- Action buttons for Team Lead and Admin roles -->
                                        <?php if (in_array($role_name, ['Team Lead', 'Admin'], true)): ?>
                                            <td>
                                                <div class="btn-group btn-group-sm">
                                                    <a href="jobs.php?mode=edit&id=<?= (int)$job['job_id'] ?>" class="btn btn-warning" title="Edit"><i class="fa fa-edit"></i></a>
                                                    <form method="post" class="d-inline" onsubmit="return confirm('Delete this job?');">
                                                        <input type="hidden" name="action" value="delete" />
                                                        <input type="hidden" name="job_id" value="<?= (int)$job['job_id'] ?>" />
                                                        <button type="submit" class="btn btn-danger" title="Delete"><i class="fa fa-trash"></i></button>
                                                    </form>
                                                </div>
                                            </td>
                                        <?php endif; ?>
                                    </tr>
                                <?php endwhile; else: ?>
                                    <!-- Message if no jobs are found -->
                                    <tr><td colspan="<?= in_array($role_name, ['Team Lead', 'Admin'], true) ? '9' : '8' ?>" class="text-center py-3">No jobs found.</td></tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        <?php endif; ?>

    </div>

    <!-- Bootstrap JavaScript bundle -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
