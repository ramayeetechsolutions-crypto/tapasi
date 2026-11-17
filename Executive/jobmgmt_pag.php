<?php
// Helper functions
function formatDateTime($datetime, $format = 'd-m-Y H:i') {
    if (empty($datetime) || 
        $datetime == '0000-00-00 00:00:00' || 
        $datetime == '0000-00-00' ||
        $datetime == '30-11--0001 00:00' ||
        $datetime == '30-11--0001') {
        return '—';
    }
    
    $dateObj = DateTime::createFromFormat('Y-m-d H:i:s', $datetime);
    if (!$dateObj) {
        $dateObj = DateTime::createFromFormat('Y-m-d', $datetime);
    }
    
    return $dateObj ? $dateObj->format($format) : '—';
}
function getWorkingDaysWithoutSunday($startDate, $endDate = null) {
    if (empty($startDate) || $startDate == '0000-00-00' || $startDate == '0000-00-00 00:00:00' || $startDate == '30-11--0001') {
        return 0;
    }
    $start = new DateTime(date('Y-m-d', strtotime($startDate)));
    $end = !empty($endDate) && $endDate != '0000-00-00' && $endDate != '0000-00-00 00:00:00' 
            ? new DateTime(date('Y-m-d', strtotime($endDate))) 
            : new DateTime(date('Y-m-d'));
            
    if ($start > $end) return 0;
    
    $days = 0;
    $curr = clone $start;
    while ($curr < $end) {
        $curr->modify('+1 day');
        if ($curr->format('w') != 0) { // 0 = Sunday skip
            $days++;
        }
    }
    return $days;
}

function formatDateOnly($date, $format = 'd-m-Y') {
    if (empty($date) || 
        $date == '0000-00-00' || 
        $date == '0000-00-00 00:00:00' ||
        $date == '30-11--0001 00:00' ||
        $date == '30-11--0001') {
        return '—';
    }
    
    $dateObj = DateTime::createFromFormat('Y-m-d', $date);
    if (!$dateObj) {
        $dateObj = DateTime::createFromFormat('Y-m-d H:i:s', $date);
    }
    
    return $dateObj ? $dateObj->format($format) : '—';
}

require 'db.php';
session_start();

// Ensure table_status & assigned_to_table columns exist in pag table
$check_ts_col = $mysqli->query("SHOW COLUMNS FROM pag LIKE 'table_status'");
if ($check_ts_col && $check_ts_col->num_rows == 0) {
    $mysqli->query("ALTER TABLE pag ADD COLUMN table_status VARCHAR(100) DEFAULT 'Need to Assign'");
}
$check_tat_col = $mysqli->query("SHOW COLUMNS FROM pag LIKE 'assigned_to_table'");
if ($check_tat_col && $check_tat_col->num_rows == 0) {
    $mysqli->query("ALTER TABLE pag ADD COLUMN assigned_to_table VARCHAR(100) NULL DEFAULT NULL");
}
$check_rem_col = $mysqli->query("SHOW COLUMNS FROM pag LIKE 'remarks'");
if ($check_rem_col && $check_rem_col->num_rows == 0) {
    $mysqli->query("ALTER TABLE pag ADD COLUMN remarks VARCHAR(255) NULL DEFAULT NULL");
}
$check_wp_col = $mysqli->query("SHOW COLUMNS FROM pag LIKE 'word_pages'");
if ($check_wp_col && $check_wp_col->num_rows == 0) {
    $mysqli->query("ALTER TABLE pag ADD COLUMN word_pages VARCHAR(50) NULL DEFAULT '0'");
}
$check_tfqa_p = $mysqli->query("SHOW COLUMNS FROM pag LIKE 'table_fqa_pages'");
if ($check_tfqa_p && $check_tfqa_p->num_rows == 0) {
    $mysqli->query("ALTER TABLE pag ADD COLUMN table_fqa_pages VARCHAR(50) NULL DEFAULT '0'");
}
$check_tfqa_s = $mysqli->query("SHOW COLUMNS FROM pag LIKE 'table_fqa_status'");
if ($check_tfqa_s && $check_tfqa_s->num_rows == 0) {
    $mysqli->query("ALTER TABLE pag ADD COLUMN table_fqa_status VARCHAR(100) DEFAULT 'Need to Assign'");
}
$check_tfqa_a = $mysqli->query("SHOW COLUMNS FROM pag LIKE 'assigned_to_table_fqa'");
if ($check_tfqa_a && $check_tfqa_a->num_rows == 0) {
    $mysqli->query("ALTER TABLE pag ADD COLUMN assigned_to_table_fqa VARCHAR(100) NULL DEFAULT NULL");
}

function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES); }

// Returns the badge class: green for completed-type statuses, otherwise info
function simpleStatusBadge($status, $default = 'Pending') {
    $st = trim((string)$status);
    if ($st === '') $st = $default;
    $done = ['Completed', 'Done', 'LE Done', 'LE-QC Done', 'LE & LE-QC Done', 'W-Complete', 'ID_Complete'];
    $isDone = false;
    foreach ($done as $d) {
        if (strcasecmp($st, $d) === 0) { $isDone = true; break; }
    }
    if ($isDone) {
        return '<span class="badge" style="background-color: #28a745; color: white; padding: 4px 8px; border-radius: 4px; font-size: 11px;">' . h($st) . '</span>';
    }
    return '<span class="badge bg-info">' . h($st) . '</span>';
}

$current_user = $_SESSION['username'] ?? '';
$uid = $_SESSION['uid'] ?? '';
if (!$current_user) {
    echo "<div class='alert alert-danger'>Session expired. Please log in again.</div>";
    exit;
}

// First, get the logged-in user's employee_name and reporting manager
$user_employee_name = '';
$logged_in_name = '';
$user_reporting_manager = '';
$user_sql = "SELECT employee_name, reporting_manager FROM employee_master WHERE employee_username = ?";
$stmt_user = $mysqli->prepare($user_sql);
$stmt_user->bind_param("s", $current_user);
$stmt_user->execute();
$stmt_user->bind_result($logged_in_name, $user_reporting_manager);
$stmt_user->fetch();
$stmt_user->close();

$user_employee_name = !empty($user_reporting_manager) ? $user_reporting_manager : $logged_in_name;

if (!$user_employee_name) {
    echo "<div class='alert alert-warning'>User profile not found. Please contact administrator.</div>";
    exit;
}

// Filters
$q = trim($_GET['q'] ?? '');
$filter_client = trim($_GET['client'] ?? '');
$filter_process = trim($_GET['process'] ?? '');
$filter_emp = trim($_GET['filter_emp'] ?? '');
$from_date = $_GET['from_date'] ?? '';
$to_date = $_GET['to_date'] ?? '';
$sort = $_GET['sort'] ?? 'j.job_date';
$valid_sorts = ['job_id','j.job_date','client_name','journal_issue','process'];
if (!in_array($sort, $valid_sorts)) $sort = 'j.job_date';

$default_dir = ($sort === 'j.job_date') ? 'DESC' : 'ASC';
$dir  = $_GET['dir'] ?? $default_dir;
$dir  = strtoupper($dir)==='ASC' ? 'ASC' : 'DESC';

$sort_map = [
    'job_id'        => 'j.job_id',
    'j.job_date'    => 'j.job_date',
    'client_name'   => "COALESCE(NULLIF(c.team_client_name, ''), c.client_name)",
    'journal_issue' => 'j.journal_issue',
    'process'       => 'process'
];
$order_col = $sort_map[$sort] ?? 'j.job_date';

// Process stage mapping - keeping only PAG related
$processStageMap = [
    'PAG' => ['PAG'],
    'CE+PAG' => ['PAG'],
    'PAG+XML' => ['PAG'],
    'CE+PAG+XML' => ['PAG'],
    'TS' => ['PAG'],
    'CE+TS' => ['PAG'],
    'TS+XML' => ['PAG'],
    'CE+TS+XML' => ['PAG'],
];

// Get unique team members under this reporting manager to keep their jobs visible
$team_users = [];
$unique_team_members = [];
$stmt_team = $mysqli->prepare("SELECT employee_username, employee_name FROM employee_master WHERE (reporting_manager = ? OR reporting_manager = ? OR reporting_manager LIKE '%Bragadeeshwaran%' OR employee_name LIKE '%Bragadeeshwaran%' OR team_id = 2) AND status = 'Active' ORDER BY employee_name ASC");
if ($stmt_team) {
    $stmt_team->bind_param("ss", $user_employee_name, $logged_in_name);
    $stmt_team->execute();
    $res_team = $stmt_team->get_result();
    while ($row_team = $res_team->fetch_assoc()) {
        $team_users[] = "'" . $mysqli->real_escape_string($row_team['employee_username']) . "'";
        $unique_team_members[$row_team['employee_username']] = $row_team['employee_name'];
    }
    $stmt_team->close();
}

if (!empty($current_user) && !in_array("'" . $mysqli->real_escape_string($current_user) . "'", $team_users)) {
    $team_users[] = "'" . $mysqli->real_escape_string($current_user) . "'";
}
if (!empty($current_user) && !isset($unique_team_members[$current_user])) {
    $unique_team_members[$current_user] = $logged_in_name;
}

$team_condition = "j.assigned_to = ?";
if (!empty($team_users)) {
    $team_list = implode(',', $team_users);
    $team_condition = "(j.assigned_to = ? OR j.assigned_to = ? OR j.assigned_to LIKE '%Bragadeeshwaran%' OR pag.assigned_to_pag IN ($team_list) OR pag.assigned_to_img IN ($team_list) OR j.assigned_to IN ($team_list) OR j.assigned_pag IN ($team_list))";
}
// FIX: Show jobs that have 'PAG' or 'TS' in their process name
$where[] = "(jm.job_name LIKE '%PAG%' OR jm.job_name LIKE '%TS%')";

// WHERE conditions - Show jobs for the team
$where = [$team_condition];
$params = [$user_employee_name, $logged_in_name];
$types  = 'ss';

// Always restrict TS Report to only TS / PAG processes
$where[] = "jm.job_name IN ('PAG', 'PAG+XML', 'CE+PAG', 'CE+PAG+XML', 'TS', 'TS+XML', 'CE+TS', 'CE+TS+XML')";

// Search query filter
if ($q) {
    $where[] = "(c.client_name LIKE ? OR c.team_client_name LIKE ? OR j.journal_issue LIKE ? OR j.article LIKE ?)";
    $params = array_merge($params, ["%$q%", "%$q%", "%$q%", "%$q%"]);
    $types .= "ssss";
}

// Client filter
if ($filter_client) {
    $where[] = "(c.client_name LIKE ? OR c.team_client_name LIKE ?)";
    $params[] = "%$filter_client%";
    $params[] = "%$filter_client%";
    $types .= "ss";
}

// Process filter
if ($filter_process) {
    if ($filter_process === 'TS' || $filter_process === 'PAG') {
        $where[] = "jm.job_name IN ('TS', 'PAG')";
    } elseif ($filter_process === 'CE+TS' || $filter_process === 'CE+PAG') {
        $where[] = "jm.job_name IN ('CE+TS', 'CE+PAG')";
    } elseif ($filter_process === 'TS+XML' || $filter_process === 'PAG+XML') {
        $where[] = "jm.job_name IN ('TS+XML', 'PAG+XML')";
    } elseif ($filter_process === 'CE+TS+XML' || $filter_process === 'CE+PAG+XML') {
        $where[] = "jm.job_name IN ('CE+TS+XML', 'CE+PAG+XML')";
    } else {
        $where[] = "jm.job_name = ?";
        $params[] = $filter_process;
        $types .= "s";
    }
}

// Date range filters
if ($from_date) {
    $where[] = "j.job_date >= ?";
    $params[] = $from_date;
    $types .= "s";
}
if ($to_date) {
    $where[] = "j.job_date <= ?";
    $params[] = $to_date;
    $types .= "s";
}

// Employee filter (PAG / Word Img)
if ($filter_emp) {
    $where[] = "(pag.assigned_to_pag = ? OR pag.assigned_to_img = ? OR j.assigned_pag = ?)";
    $params[] = $filter_emp;
    $params[] = $filter_emp;
    $params[] = $filter_emp;
    $types .= "sss";
}

// Build WHERE SQL
$where_sql = !empty($where) ? "WHERE " . implode(" AND ", $where) : "";

// MODIFIED QUERY: Fetching all required fields to match TS Assign view
$sql = "SELECT 
    j.job_id, j.job_date, c.client_name, c.team_client_name, j.journal_issue, j.article,
    jm.job_name AS process, j.dispatch_date, j.file_path as jfile_path, j.assigned_to, j.due_date,
    
    pag.status AS pag_status, pag.pag_status_col, pag.word_status,
    pag.created_at, pag.pag_date, pag.done_by AS pag_done_by,
    pag.eq, pag.pages, pag.images, pag.remarks, pag.tables_count, pag.word_pages,
    pag.table_fqa_pages, pag.table_fqa_status, pag.assigned_to_table_fqa,
    pag.table_status, pag.assigned_to_table,
    pag.assigned_to_img, pag.assigned_to_pag,
    pag.img_start_time, pag.img_end_time, pag.pag_start_time, pag.pag_end_time,
    pag.path, pag.priority,
    j.assigned_pag,
    
    ju.upload_date, ju.upload_by, ju.file_path,
    
    le.last_updated as le_moved_date, ce.ce_date as ce_moved_date,
    ce.status as ce_status, ce.remarks as ce_remarks, ce.assigned_to_content as ce_assigned,
    le.status as le_status, le.assigned_to_le as le_assigned_to, qc.status as qc_status,
    qc.rtat, qc.atat,
    qc_crx.pag_crx_stage, qc_crx.pag_crx_assigned_to,
    qc_crx.pag_crx_start_time, qc_crx.pag_crx_end_time,
    qc_crx.img_tab_stage

FROM jobs j
JOIN client_master c ON j.client_id=c.client_id
JOIN job_master jm ON j.process_id=jm.job_id
LEFT JOIN pag ON pag.job_id=j.job_id
LEFT JOIN ce ON ce.job_id=j.job_id
LEFT JOIN le ON le.job_id=j.job_id
LEFT JOIN qc ON qc.job_id=j.job_id
LEFT JOIN qc_crx ON qc_crx.job_id=j.job_id
LEFT JOIN (
    SELECT job_id, upload_date, upload_by, file_path
    FROM job_uploads 
    WHERE id IN (
        SELECT MAX(id) FROM job_uploads GROUP BY job_id
    )
) ju ON ju.job_id = j.job_id

$where_sql
ORDER BY $order_col $dir, j.job_id DESC";

$stmt = $mysqli->prepare($sql);
if (!$stmt) die("Prepare failed: ".$mysqli->error);
if ($params) $stmt->bind_param($types, ...$params);
$stmt->execute();
$results = $stmt->get_result();

// Get unique clients for search dropdown
$clients_query = "SELECT DISTINCT client_name, team_client_name FROM client_master ORDER BY client_name ASC";
$clients_result = $mysqli->query($clients_query);

// Fetch employee names for mapping
$emp_map = [];
$emp_res = $mysqli->query("SELECT employee_username, employee_name FROM employee_master");
if ($emp_res) {
    while ($e = $emp_res->fetch_assoc()) {
        $emp_map[$e['employee_username']] = $e['employee_name'];
    }
}

// Calculate final status counts for dashboard - now only based on PAG
$status_counts = [
    'Need to Assign' => 0,
    'In Progress' => 0,
    'Completed' => 0,
    'Total' => 0
];

$all_jobs = [];
if ($results->num_rows > 0) {
    $results->data_seek(0);
    while($row = $results->fetch_assoc()) {
        $process_name = $row['process'];
        $normalized_process = strtoupper(str_replace(' ', '', $process_name));
        $required_stages = $processStageMap[$normalized_process] ?? ['PAG']; // Default to PAG
        
        // Determine final status based on PAG only (with qc_crx fallback)
        $pag_status = trim($row['pag_status'] ?? '');
        $is_done = in_array($pag_status, ['Completed', 'Done']) || in_array(trim($row['pag_crx_stage'] ?? ''), ['PAG_CRX_Compelete', 'Completed', 'Done']);
        if ($is_done) {
            $final_status = 'Completed';
        } elseif (empty($pag_status) || $pag_status === 'Need to Assign') {
            $final_status = 'Need to Assign';
        } else {
            $final_status = 'In Progress';
        }
        
        $status_counts[$final_status]++;
        $status_counts['Total']++;
        
        // Store for table display
        $row['final_status'] = $final_status;
        $all_jobs[] = $row;
    }
}

require 'header.php';
?>
<br><br><br>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css"/>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons/font/bootstrap-icons.css"/>
<!-- Select2 CSS -->
<link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
<style>
/* Ash Page Header Banner (Matching Navbar Color) */
        .page-title-container {
            background: var(--ash, #6c757d);
            color: white;
            padding: 12px 20px;
            border-radius: 12px;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
            flex-wrap: wrap;
            gap: 15px;
        }

        .page-title-container h1,
        .page-title-container h2,
        .page-title-container h3,
        .page-title-container h4,
        .page-title-container h5,
        .page-title-container h6 {
            margin: 0;
            font-size: 1.25rem;
            font-weight: 600;
            color: white;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .page-title-container p {
            color: rgba(255, 255, 255, 0.85);
            margin: 0;
        }

        .page-title-container i {
            color: white;
        }

/* Select2 Custom Style - Exactly matching CE & LE Report (26px height & 11px font) */
.select2-container .select2-selection--single {
    height: 26px !important;
    min-height: 26px !important;
    border: 1px solid #dee2e6 !important;
    border-radius: 0.25rem !important;
    background-color: #fff !important;
    display: flex !important;
    align-items: center !important;
    transition: border-color .15s ease-in-out, box-shadow .15s ease-in-out !important;
}
.select2-container--default.select2-container--open .select2-selection--single,
.select2-container--default.select2-container--focus .select2-selection--single {
    border-color: #86b7fe !important;
    box-shadow: 0 0 0 0.25rem rgba(13, 110, 253, 0.25) !important;
    outline: 0 !important;
}
.select2-container--default .select2-selection--single .select2-selection__rendered {
    line-height: 24px !important;
    font-size: 11px !important;
    font-family: Arial, Helvetica, sans-serif !important;
    color: #212529 !important;
    padding-left: 0.5rem !important;
    padding-right: 1.75rem !important;
}
.select2-container--default .select2-selection--single .select2-selection__arrow {
    height: 24px !important;
    right: 6px !important;
    width: 14px !important;
    top: 1px !important;
    display: flex !important;
    align-items: center !important;
    justify-content: center !important;
}
.select2-container--default .select2-selection--single .select2-selection__arrow b {
    display: none !important;
}
.select2-container--default .select2-selection--single .select2-selection__arrow::after {
    content: "" !important;
    display: inline-block !important;
    width: 10px !important;
    height: 10px !important;
    background-image: url("data:image/svg+xml,%3csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 16 16'%3e%3cpath fill='none' stroke='%23343a40' stroke-linecap='round' stroke-linejoin='round' stroke-width='2' d='m2 5 6 6 6-6'/%3e%3c/svg%3e") !important;
    background-repeat: no-repeat !important;
    background-size: 10px 10px !important;
    background-position: center !important;
}
.select2-dropdown {
    border: 1px solid #ced4da !important;
    border-radius: 0.25rem !important;
    background-color: #fff !important;
    box-shadow: 0 0.5rem 1rem rgba(0, 0, 0, 0.15) !important;
    margin-top: 1px !important;
    overflow: hidden !important;
}
.select2-container--default .select2-search--dropdown {
    padding: 4px 6px !important;
}
.select2-container--default .select2-search--dropdown .select2-search__field {
    font-size: 11px !important;
    font-family: Arial, Helvetica, sans-serif !important;
    border: 1px solid #ced4da !important;
    border-radius: 0.25rem !important;
    padding: 3px 6px !important;
    height: 24px !important;
    outline: none !important;
}
.select2-results__options {
    overflow-x: hidden !important;
}
.select2-container--default .select2-results__option {
    font-size: 11px !important;
    font-family: Arial, Helvetica, sans-serif !important;
    padding: 4px 10px !important;
    color: #212529 !important;
    white-space: nowrap !important;
    overflow: hidden !important;
    text-overflow: ellipsis !important;
}
.select2-container--default .select2-results__option--highlighted,
.select2-container--default .select2-results__option--highlighted[aria-selected],
.select2-container--default .select2-results__option[aria-selected="true"],
.select2-container--default .select2-results__option--selected {
    background-color: #0d6efd !important;
    color: #ffffff !important;
}
table td { vertical-align: middle; padding: 6px 8px; white-space: nowrap; font-size: 0.9rem; }
.table thead th {
    background-color: #707981 !important;
    font-weight: bold;
    font-size: 0.85rem;
    text-transform: uppercase;
    letter-spacing: 0.025em;
    color: white !important;
    padding: 0.8rem 0.5rem;
    border: 1px solid #5a6268 !important;
    white-space: nowrap;
}

/* --- Vibrant Color Scheme --- */
.status-in-progress {
    background-color: #FF6347; 
    color: white; 
    font-weight: 600;
    border-radius: 12px;
    padding: 0.3em 0.8em;
    font-size: 0.9em;
    display: inline-block;
}

.status-completed {
    background-color: #009688; 
    color: white; 
    font-weight: 600;
    border-radius: 12px;
    padding: 0.3em 0.8em;
    font-size: 0.9em;
    display: inline-block;
}

.status-need-to-assign {
    background-color: #FFA500; 
    color: white; 
    font-weight: 600;
    border-radius: 12px;
    padding: 0.3em 0.8em;
    font-size: 0.9em;
    display: inline-block;
}

/* Date and time styling */
table td {
    font-size: 0.85rem;
}

.badge {
    font-size: 0.75rem;
}

/* Dashboard Cards */
.dashboard-card {
    border-radius: 10px;
    padding: 15px;
    margin-bottom: 15px;
    color: white;
    text-align: center;
    box-shadow: 0 4px 6px rgba(0,0,0,0.1);
}
.card-total { background: linear-gradient(45deg, #667eea, #764ba2); }
.card-completed { background: linear-gradient(45deg, #11998e, #38ef7d); }
.card-progress { background: linear-gradient(45deg, #ff8c00, #ff2d00); }
.card-pending { background: linear-gradient(45deg, #f46b45, #eea849); }

.dashboard-card .count {
    font-size: 2rem;
    font-weight: bold;
    margin-bottom: 5px;
}
.dashboard-card .label {
    font-size: 0.9rem;
    opacity: 0.9;
}

/* Team Leader Badge */
.team-leader-badge {
    background: linear-gradient(45deg, #8e2de2, #4a00e0);
    color: white;
    border-radius: 20px;
    padding: 8px 16px;
    font-size: 1rem;
    font-weight: 600;
    display: inline-block;
}

/* Assigned TL Column */
.tl-column {
    min-width: 150px;
}
</style>
<?php
function getStatusBadge($status, $username = '', $emp_map = []) {
    $st = trim((string)$status);
    if (empty($st) || $st === 'Need to Assign') $st = 'Need to Assign';
    
    if (strcasecmp($st, 'Completed') === 0 || strcasecmp($st, 'W-Complete') === 0 || strcasecmp($st, 'ID_Complete') === 0 || strcasecmp($st, 'FQA_Complete') === 0) {
        $comp_label = (strcasecmp($st, 'FQA_Complete') === 0) ? 'FQA_Complete' : 'Completed';
        $badge = '<span class="badge" style="background-color: #28a745; color: white; padding: 4px 8px; border-radius: 4px; font-size: 11px;">' . $comp_label . '</span>';
    } elseif (strcasecmp($st, 'Need to Assign') === 0) {
        $badge = '<span class="badge" style="background-color: #0d6efd; color: white; padding: 4px 8px; border-radius: 4px; font-size: 11px;">Need to Assign</span>';
    } elseif (strcasecmp($st, 'Pending') === 0) {
        $badge = '<span class="badge" style="background-color: #ffc107; color: black; padding: 4px 8px; border-radius: 4px; font-size: 11px;">Pending</span>';
    } else {
        $label = (strcasecmp($st, 'In Progress') === 0 || strcasecmp($st, 'W-In process') === 0) ? 'In Progress' : h($st);
        $badge = '<span class="badge" style="background-color: #fd7e14; color: white; padding: 4px 8px; border-radius: 4px; font-size: 11px;">' . $label . '</span>';
    }
    
    if (!empty($username)) {
        $name = $emp_map[$username] ?? '';
        $display = $name ? h($name) . " (" . h($username) . ")" : h($username);
        return $badge . '<br><small style="color: #6c757d; font-size: 10px; font-weight: 600; display: block; margin-top: 4px; white-space: nowrap;">' . $display . '</small>';
    }
    return $badge;
}
?>
<div class="container-fluid">
    <!-- <div class="page-title-container"> -->
        <h2 class="mb-0 text-white">TS Report</h2>
        <!-- <div class="team-leader-badge">
            <i class="fas fa-user-tie me-1"></i> <?= h($user_employee_name) ?>
        </div> -->
    </div>

    <!-- Dashboard Cards -->
    <div class="row mb-4">
        <div class="col-md-3">
            <div class="dashboard-card card-total">
                <div class="count"><?= $status_counts['Total'] ?></div>
                <div class="label">Total Jobs</div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="dashboard-card card-completed">
                <div class="count"><?= $status_counts['Completed'] ?></div>
                <div class="label">TS Completed</div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="dashboard-card card-progress">
                <div class="count"><?= $status_counts['In Progress'] ?></div>
                <div class="label">TS In Progress</div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="dashboard-card card-pending">
                <div class="count"><?= $status_counts['Need to Assign'] ?></div>
                <div class="label">TS Need to Assign</div>
            </div>
        </div>
    </div>

    <!-- Filter Form -->
    <div class="card mb-4">
        <div class="card-body">
            <form method="get" class="row gy-2 gx-3 align-items-center">
                <div class="col-md-2">
                    <label class="form-label small mb-1">Search</label>
                    <input type="text" name="q" placeholder="Client/Issue/Article" value="<?=h($q)?>" class="form-control form-control-sm">
                </div>
                
                <div class="col-md-2">
                    <label class="form-label small mb-1">Client</label>
                    <select name="client" id="clientSearchDropdown" class="form-select form-select-sm">
                        <option value="">All Clients...</option>
                        <?php if (isset($clients_result) && $clients_result): ?>
                            <?php 
                            $displayed_clients = [];
                            while ($cli = $clients_result->fetch_assoc()): 
                                $display_name = !empty($cli['team_client_name']) ? $cli['team_client_name'] : $cli['client_name'];
                                if (!in_array($display_name, $displayed_clients)):
                                    $displayed_clients[] = $display_name;
                            ?>
                                <option value="<?= h($display_name) ?>" <?= $filter_client === $display_name ? 'selected' : '' ?>>
                                    <?= h($display_name) ?>
                                </option>
                            <?php 
                                endif;
                            endwhile; 
                            ?>
                        <?php endif; ?>
                    </select>
                </div>
                
                <div class="col-md-2">
                    <label class="form-label small mb-1">Process</label>
                    <select name="process" class="form-select form-select-sm">
                        <option value="">All Processes</option>
                        <option value="TS" <?=$filter_process==='TS'||$filter_process==='PAG'?'selected':''?>>TS</option>
                        <option value="CE+TS" <?=$filter_process==='CE+TS'||$filter_process==='CE+PAG'?'selected':''?>>CE+TS</option>
                        <option value="TS+XML" <?=$filter_process==='TS+XML'||$filter_process==='PAG+XML'?'selected':''?>>TS+XML</option>
                        <option value="CE+TS+XML" <?=$filter_process==='CE+TS+XML'||$filter_process==='CE+PAG+XML'?'selected':''?>>CE+TS+XML</option>
                    </select>
                </div>
                
                <div class="col-md-2">
                    <label class="form-label small mb-1">Employee</label>
                    <select name="filter_emp" class="form-select form-select-sm">
                        <option value="">All Team Members</option>
                        <?php foreach ($unique_team_members as $uname => $ename): ?>
                            <option value="<?= h($uname) ?>" <?= $filter_emp === $uname ? 'selected' : '' ?>>
                                <?= h($ename) ?> (<?= h($uname) ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="col-md-2">
                    <label class="form-label small mb-1">From Date</label>
                    <input type="date" name="from_date" value="<?= h($from_date) ?>" class="form-control form-control-sm">
                </div>
                
                <div class="col-md-2">
                    <label class="form-label small mb-1">To Date</label>
                    <input type="date" name="to_date" value="<?= h($to_date) ?>" class="form-control form-control-sm">
                </div>
                
                <div class="col-md-2">
                    <label class="form-label small mb-1">Sort By</label>
                    <select name="sort" class="form-select form-select-sm">
                        <option value="j.job_date" <?=$sort==='j.job_date'?'selected':''?>>Date</option>
                        <option value="client_name" <?=$sort==='client_name'?'selected':''?>>Client</option>
                        <option value="journal_issue" <?=$sort==='journal_issue'?'selected':''?>>Issue</option>
                        <option value="process" <?=$sort==='process'?'selected':''?>>Process</option>
                    </select>
                </div>
                
                <!-- <div class="col-md-1">
                    <label class="form-label small mb-1">Order</label>
                    <select name="dir" class="form-select form-select-sm">
                        <option value="DESC" <?=$dir==='DESC'?'selected':''?>>Desc</option>
                        <option value="ASC" <?=$dir==='ASC'?'selected':''?>>Asc</option>
                    </select>
                </div> -->
                
                <div class="col-md-2">
                    <label class="form-label small mb-1 d-block">&nbsp;</label>
                    <button class="btn btn-primary btn-sm" type="submit">
                        <i class="fas fa-search"></i> Filter
                    </button>
                    <a href="jobmgmt_pag.php" class="btn btn-outline-secondary btn-sm">
                        <i class="fas fa-times"></i> Clear
                    </a>
                </div>
            </form>
        </div>
    </div>

    <!-- Table -->
    <div class="card">
        <div class="card-body p-0">
            <div class="table-responsive" style="max-height:650px; overflow:auto;">
                <table class="table table-striped table-bordered" id="jobTable">
                    <thead class="table-light">
                        <tr>
                            <th>LE moved Date</th>
                            <th>CE moved Date</th>
                            <th>Received Date</th>
                            <th>Client</th>
                            <th>Journals</th>
                            <th>Articles</th>
                            <th>EQN</th>
                            <th>Pages</th>
                            <th>Images</th>
                            <th>Remarks</th>
                            <th>Tables</th>
                            <th>Table_FQA</th>
                            <th>Process</th>
                            <th>Due Date</th>
                            <th>FQA Status</th>
                            <th>LE Status</th>
                            <th>Word Process</th>
                            <th>Word Start</th>
                            <th>Word End</th>
                            <th>TS</th>
                            <th>TS Start</th>
                            <th>TS End</th>
                            <th>R-TAT</th>
                            <th>A-TAT</th>
                            <th>Path</th>
                            <th>Priority</th>
                            <th>TAT</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (count($all_jobs) > 0): 
                            foreach($all_jobs as $row): 
                        ?>
                        <tr>
                            <td><?= (in_array($row['le_status'], ['Completed', 'Done', 'LE & LE-QC Done', 'LE-QC Done', 'LE Done']) && !empty($row['le_moved_date'])) ? formatDateOnly(substr($row['le_moved_date'], 0, 10), 'd-m-Y') : '—' ?></td>
                            <td><?= !empty($row['ce_moved_date']) ? formatDateOnly(substr($row['ce_moved_date'], 0, 10), 'd-m-Y') : '—' ?></td>
                            <td><?= !empty($row['created_at']) ? formatDateOnly(substr($row['created_at'], 0, 10), 'd-m-Y') : '—' ?></td>
                            <td><?= h(!empty($row['team_client_name']) ? $row['team_client_name'] : $row['client_name']) ?></td>
                            <td><?= h($row['journal_issue']) ?></td>
                            <td><?= h($row['article']) ?></td>
                            <td><?= h($row['eq'] ?? '0') ?></td>
                            <td><?= h($row['pages'] ?? '0') ?></td>
                            <td><?= h($row['images'] ?? '0') ?></td>
                            <td>
                                <?php if (!empty($row['remarks'])): ?>
                                    <span class="badge bg-light text-dark border"><?= h($row['remarks']) ?></span>
                                <?php else: ?>
                                    —
                                <?php endif; ?>
                            </td>
                            <td>
                                <div><strong><?= h($row['tables_count'] ?? '0') ?></strong></div>
                                <?php
                                    $table_emp = trim((string)($row['assigned_to_table'] ?? ''));
                                    $table_stat = trim((string)($row['table_status'] ?? ''));
                                    $is_pag_done_row = in_array(($row['pag_status'] ?? ''), ['Completed', 'Done']) || in_array(($row['pag_crx_stage'] ?? ''), ['PAG_CRX_Compelete', 'Completed', 'Done']);
                                    if (strcasecmp($table_stat, 'Completed') === 0) {
                                        $table_badge_stat = 'Completed';
                                    } elseif (strcasecmp($table_stat, 'In Progress') === 0 || !empty($table_emp)) {
                                        $table_badge_stat = 'In Progress';
                                    } elseif (!$is_pag_done_row) {
                                        $table_badge_stat = 'Need to Assign';
                                    } else {
                                        $table_badge_stat = '';
                                    }
                                    if ($table_badge_stat !== '') {
                                        echo '<div class="mt-1">' . getStatusBadge($table_badge_stat, $table_emp, $emp_map) . '</div>';
                                    }
                                ?>
                            </td>
                            <td>
                                <div><strong><?= h(($row['table_fqa_pages'] !== null && $row['table_fqa_pages'] !== '') ? $row['table_fqa_pages'] : '0') ?></strong></div>
                                <?php
                                    $tfqa_emp = trim((string)($row['assigned_to_table_fqa'] ?? ''));
                                    $tfqa_stat = trim((string)($row['table_fqa_status'] ?? ''));
                                    if (strcasecmp($tfqa_stat, 'FQA_Complete') === 0 || strcasecmp($tfqa_stat, 'Completed') === 0) {
                                        $tfqa_badge_stat = 'FQA_Complete';
                                    } elseif (strcasecmp($tfqa_stat, 'FQA_Inprocess') === 0 || strcasecmp($tfqa_stat, 'In Progress') === 0 || !empty($tfqa_emp)) {
                                        $tfqa_badge_stat = 'FQA_Inprocess';
                                    } else {
                                        $tfqa_badge_stat = 'Need to Assign';
                                    }
                                    echo '<div class="mt-1">' . getStatusBadge($tfqa_badge_stat, $tfqa_emp, $emp_map) . '</div>';
                                ?>
                            </td>
                            <td><span class="badge bg-secondary"><?= h($row['process']) ?></span></td>
                            <td><?= formatDateOnly($row['due_date'], 'd-m-Y') ?></td>
                            <?php
                                $fqa_proc = strtoupper(str_replace(' ', '', $row['process'] ?? ''));
                                $has_ce_proc = (strpos($fqa_proc, 'CE') !== false);
                                if (!$has_ce_proc) {
                                    // Only TS process: show only Pending like LE status
                                    $fqa_status_val = 'Pending';
                                    $fqa_emp_val = '';
                                } else {
                                    $ce_is_done = in_array($row['ce_status'] ?? '', ['Completed', 'Done']) || trim($row['ce_remarks'] ?? '') === 'Moved' || !empty($row['ce_moved_date']);
                                    $fqa_emp_val = trim((string)($row['ce_assigned'] ?? ''));
                                    if ($ce_is_done) {
                                        $fqa_status_val = 'Completed';
                                        if ($fqa_emp_val === '') $fqa_emp_val = trim((string)($row['assigned_to'] ?? ''));
                                    } elseif (!empty($fqa_emp_val)) {
                                        $fqa_status_val = !empty($row['ce_status']) ? $row['ce_status'] : 'In Progress';
                                    } elseif (!empty($row['ce_status']) && strcasecmp($row['ce_status'], 'Need to Assign') !== 0) {
                                        $fqa_status_val = $row['ce_status'];
                                    } else {
                                        $fqa_status_val = 'Need to Assign';
                                    }
                                }
                            ?>
                            <td><?= getStatusBadge($fqa_status_val, $fqa_emp_val, $emp_map) ?></td>
                            <td><?= getStatusBadge($row['le_status'] ?? 'Pending', $row['le_assigned_to'] ?? '', $emp_map) ?></td>
                            
                            <!-- Word Process & Times -->
                            <?php
                                $is_pag_completed = in_array(($row['pag_status'] ?? ''), ['Completed', 'Done']) || in_array(($row['pag_crx_stage'] ?? ''), ['PAG_CRX_Compelete', 'Completed', 'Done']);
                                
                                // Word Process
                                $word_stat = trim((string)($row['word_status'] ?? ''));
                                if (empty($word_stat) || $word_stat === 'Need to Assign') {
                                    if ($is_pag_completed || in_array(($row['img_tab_stage'] ?? ''), ['IMG_COMPLETE', 'TAB_COMPLETE', 'IMG_TAB_COMPLETE'])) {
                                        $word_stat = 'Completed';
                                    } else {
                                        $word_stat = 'Need to Assign';
                                    }
                                }
                                $word_emp = !empty($row['assigned_to_img']) ? $row['assigned_to_img'] : (!empty($row['assigned_to_pag']) ? $row['assigned_to_pag'] : (!empty($row['pag_crx_assigned_to']) ? $row['pag_crx_assigned_to'] : ($row['assigned_pag'] ?? '')));
                                
                                $word_start = '—';
                                if (!empty($row['img_start_time'])) {
                                    $word_start = date('H:i', strtotime($row['img_start_time']));
                                } elseif (!empty($row['pag_start_time'])) {
                                    $word_start = date('H:i', strtotime($row['pag_start_time']));
                                } elseif (!empty($row['pag_crx_start_time'])) {
                                    $word_start = date('H:i', strtotime($row['pag_crx_start_time']));
                                }

                                $word_end = '—';
                                if (!empty($row['img_end_time'])) {
                                    $word_end = date('H:i', strtotime($row['img_end_time']));
                                } elseif (!empty($row['pag_end_time'])) {
                                    $word_end = date('H:i', strtotime($row['pag_end_time']));
                                } elseif (!empty($row['pag_crx_end_time'])) {
                                    $word_end = date('H:i', strtotime($row['pag_crx_end_time']));
                                }

                                // TS Process
                                $ts_stat = trim((string)($row['pag_status_col'] ?? ''));
                                if (empty($ts_stat) || $ts_stat === 'Need to Assign') {
                                    if ($is_pag_completed) {
                                        $ts_stat = 'Completed';
                                    } else {
                                        $ts_stat = 'Need to Assign';
                                    }
                                }
                                $ts_emp = !empty($row['assigned_to_pag']) ? $row['assigned_to_pag'] : (!empty($row['pag_crx_assigned_to']) ? $row['pag_crx_assigned_to'] : ($row['assigned_pag'] ?? ''));
                                
                                $ts_start = '—';
                                if (!empty($row['pag_start_time'])) {
                                    $ts_start = date('H:i', strtotime($row['pag_start_time']));
                                } elseif (!empty($row['pag_crx_start_time'])) {
                                    $ts_start = date('H:i', strtotime($row['pag_crx_start_time']));
                                }

                                $ts_end = '—';
                                if (!empty($row['pag_end_time'])) {
                                    $ts_end = date('H:i', strtotime($row['pag_end_time']));
                                } elseif (!empty($row['pag_crx_end_time'])) {
                                    $ts_end = date('H:i', strtotime($row['pag_crx_end_time']));
                                }
                            ?>
                            <td>
                                <div><strong><?= h(($row['word_pages'] !== null && $row['word_pages'] !== '') ? $row['word_pages'] : '0') ?></strong></div>
                                <div class="mt-1"><?= getStatusBadge($word_stat, $word_emp, $emp_map) ?></div>
                            </td>
                            <td><?= $word_start ?></td>
                            <td><?= $word_end ?></td>
                            
                            <!-- TS Process & Times -->
                            <td><?= getStatusBadge($ts_stat, $ts_emp, $emp_map) ?></td>
                            <td><?= $ts_start ?></td>
                            <td><?= $ts_end ?></td>
                            
                            <!-- TAT, Path & Priority -->
                            <td class="fw-bold"><?= intval($row['rtat'] ?? 0) ?></td>
                            <td class="fw-bold"><?= intval($row['atat'] ?? 0) ?></td>
                            <td>
                                <?php if(!empty($row['path'])): ?>
                                    <span class="badge <?= (strtoupper($row['path']) === 'CRX') ? 'bg-warning text-dark' : 'bg-success text-white' ?>">
                                        <?= h($row['path']) ?>
                                    </span>
                                <?php else: ?>
                                    —
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if(!empty($row['priority'])): ?>
                                    <span class="badge <?= ($row['priority'] === 'Urgent') ? 'bg-danger' : 'bg-warning text-dark' ?>">
                                        <?= h($row['priority']) ?>
                                    </span>
                                <?php else: ?>
                                    —
                                <?php endif; ?>
                            </td>
                            <!-- TAT Column -->
                            <?php
                                $pag_done = in_array($row['pag_status'] ?? '', ['Completed', 'Done']) || in_array($row['pag_crx_stage'] ?? '', ['PAG_CRX_Compelete', 'Completed', 'Done']);
                                $end_d = $pag_done ? (($row['pag_date'] ?? null) ?: ($row['last_updated'] ?? null) ?: date('Y-m-d')) : null;
                                $tat = getWorkingDaysWithoutSunday($row['job_date'] ?? '', $end_d);
                                $bg = $pag_done ? 'bg-success' : ($tat > 5 ? 'bg-danger' : 'bg-warning text-dark');
                            ?>
                            <td>
                                <span class="badge <?= $bg ?>"><?= $tat ?> <?= ($tat == 1) ? 'Day' : 'Days' ?></span>
                            </td>
                        </tr>
                        <?php endforeach; else: ?>
                        <tr><td colspan="27" class="text-center">No jobs found assigned to you.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
<script>
$(document).ready(function() {
    $('#clientSearchDropdown').select2({ width: '100%' });
});

function exportTableToExcel(tableID, filename=''){
  var table = document.getElementById(tableID);
  var html = table.outerHTML.replace(/ /g, '%20');
  var downloadLink = document.createElement("a");
  filename = filename ? filename + '.xls' : 'export.xls';
  downloadLink.href = 'data:application/vnd.ms-excel,' + html;
  downloadLink.download = filename;
  document.body.appendChild(downloadLink);
  downloadLink.click();
  document.body.removeChild(downloadLink);
}
</script>