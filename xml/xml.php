<?php
// Start a session if one is not already active
if (session_status() === PHP_SESSION_NONE) {
    session_start();
    if(isset($_SESSION['uid'])) $exeid=$_SESSION['uid'];
}

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require 'db.php';
if ($mysqli->connect_error) {
    header('Content-Type: application/json');
    die(json_encode(["error" => "Connection failed: " . $mysqli->connect_error]));
}

function h($s) {
    return htmlspecialchars((string)$s, ENT_QUOTES);
}

$uid = $_SESSION['uid'] ?? 0;
$username = trim($_SESSION['username'] ?? '');
$role_name = $_SESSION['role_name'] ?? '';
$show_completed = isset($_GET['show_completed']) && $_GET['show_completed'] == '1';

// Handle filter parameters
$search = trim($_GET['search'] ?? '');
$filter_client = trim($_GET['client'] ?? '');
$filter_process = trim($_GET['process'] ?? '');
$filter_job_status = trim($_GET['job_status'] ?? '');
$filter_date_from = trim($_GET['date_from'] ?? '');
$filter_date_to = trim($_GET['date_to'] ?? '');
$filter_tat = strtolower(trim($_GET['tat'] ?? ''));
$filter_missed_tat = strtolower(trim($_GET['missed_tat'] ?? ''));
$sort = trim($_GET['sort'] ?? 'job_date');

// Fetch clients specifically for this page (XML jobs) - dynamic dropdown
$page_clients = [];
$cl_q = "SELECT DISTINCT COALESCE(NULLIF(c.team_client_name, ''), c.client_name) AS display_client, c.client_name, c.team_client_name 
         FROM jobs j 
         JOIN client_master c ON j.client_id = c.client_id 
         JOIN job_master jm ON j.process_id = jm.job_id 
         WHERE jm.job_name LIKE '%XML%' 
         ORDER BY display_client ASC";
$cl_res = $mysqli->query($cl_q);
if ($cl_res) {
    while ($cl_row = $cl_res->fetch_assoc()) {
        if (!empty($cl_row['display_client'])) {
            $page_clients[] = $cl_row;
        }
    }
}

// ==========================================
// AJAX HANDLERS
// ==========================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'full_update') {
        handleFullUpdateRequest();
    } elseif ($_POST['action'] === 'complete') {
        handleCompleteRequest();
    } elseif ($_POST['action'] === 'assign_to_me') {
        handleAssignToMeRequest();
    }
    exit;
}

if (isset($_GET['fetch']) && $_GET['fetch'] === 'data') {
    fetchData();
    exit;
}

function handleFullUpdateRequest() {
    global $mysqli, $username;
    $response = ['success' => false, 'error' => ''];
    
    $record_id = (int)$_POST['id'];
    $status = $_POST['status'] ?? '';
    $billed = $_POST['billed'] ?? '';
    $xml_status = $_POST['xml_status'] ?? '';
    $scielo_status = $_POST['scielo_status'] ?? '';
    $pubmed_status = $_POST['pubmed_status'] ?? '';
    $html_status = $_POST['html_status'] ?? '';
    $remarks = $_POST['remarks'] ?? '';
    $final_remarks = $_POST['final_remarks'] ?? '';
    $instruction = $_POST['instruction'] ?? '';
    
    $despatched_date = !empty($_POST['despatched_date']) ? $_POST['despatched_date'] : null;
    $job_status = $_POST['job_status'] ?? '';
    $status = $_POST['status'] ?? '';

    // If date is entered, force Despatched status and Green status
    if (!empty($despatched_date)) {
        if ($job_status !== 'Rework/Correction') {
            $job_status = 'Despatched';
        }
        $status = 'Completed'; 
    }
    
    try {
        $current_datetime = date('Y-m-d H:i:s');
        
        $query = "UPDATE unique_xml_jobs SET 
                  status = ?, Billed = ?, xml_status = ?, scielo_ebsco_status = ?, pubmed_biblat_status = ?, 
                  html_status = ?, remarks = ?, `final Remarks` = ?, instruction_to_image = ?, 
                  despatched_date = ?, job_status = ?,
                  updated_at = ?, updated_by = ? WHERE id = ?";
        
        $stmt = $mysqli->prepare($query);
        $stmt->bind_param("sssssssssssssi", $status, $billed, $xml_status, $scielo_status, $pubmed_status, $html_status, $remarks, $final_remarks, $instruction, $despatched_date, $job_status, $current_datetime, $username, $record_id);
        
        if (!$stmt->execute()) {
            throw new Exception("Execute failed: " . $stmt->error);
        }
        $stmt->close();
        
        $check = $mysqli->prepare("SELECT Image_user, Table_user, WPContent_user, nlm_user, jats_user, clockss_user, HTML_user, new_html_user, direct_html_user, Scielo_user, ebsco_user, pubmed_user, biblat_user, doaj_user, QC_user FROM unique_xml_jobs WHERE id = ?");
        $check->bind_param("i", $record_id);
        $check->execute();
        $res = $check->get_result()->fetch_assoc();
        $check->close();

        $all_done = true;
        $has_valid_task = false;
        
        foreach($res as $col => $val) {
            $v = trim((string)$val);
            if ($v === 'N/A' || $v === 'NA' || $v === '-') { continue; }
            
            $has_valid_task = true;
            if ($v === '' || strpos($v, '✓') === false) { 
                $all_done = false; 
                break; 
            }
        }

        if ($all_done && $has_valid_task) {
            $curr_check = $mysqli->query("SELECT job_status FROM unique_xml_jobs WHERE id = $record_id");
            $curr_job_status = ($curr_check && $row_c = $curr_check->fetch_assoc()) ? trim((string)$row_c['job_status']) : '';

            if ($curr_job_status === 'Rework/Correction') {
                $mysqli->query("UPDATE unique_xml_jobs SET status = 'Completed', despatched_date = IF(despatched_date IS NULL OR despatched_date < '2000-01-01', NOW(), despatched_date), updated_at = NOW() WHERE id = $record_id");
            } else {
                $mysqli->query("UPDATE unique_xml_jobs SET status = 'Completed', job_status = 'Despatched', updated_at = NOW() WHERE id = $record_id");
            }
            $status = 'Completed'; 
        }
        // ----------------------------------------------------------------------------------------------------

        if ($status === 'Completed' && ($curr_job_status ?? '') !== 'Rework/Correction') {
            $stmt = $mysqli->prepare("SELECT job_id FROM unique_xml_jobs WHERE id = ?");
            $stmt->bind_param("i", $record_id);
            $stmt->execute();
            $stmt->bind_result($xml_job_id);
            if ($stmt->fetch() && $xml_job_id) {
                $stmt->close();
                updateJobsTableForXMLCompletion($xml_job_id, $username);
            } else {
                $stmt->close();
            }
        }
        
        $response['success'] = true;
        $response['message'] = 'Row details saved successfully.';
    } catch (Exception $e) {
        $response['error'] = $e->getMessage();
    }
    
    header('Content-Type: application/json');
    echo json_encode($response);
    exit;
}

function handleAssignToMeRequest() {
    global $mysqli, $username;
    $response = ['success' => false, 'error' => '', 'message' => ''];
    
    $record_id = (int)$_POST['id'];
    $field = $_POST['field'] ?? '';
    
    try {
        $assign_value = $username . '/' . date('d-m');
        
        $stat_check = $mysqli->query("SELECT job_status FROM unique_xml_jobs WHERE id = $record_id");
        $curr_stat = ($stat_check && $sc_row = $stat_check->fetch_assoc()) ? trim((string)$sc_row['job_status']) : '';

        if ($curr_stat === 'Rework/Correction') {
            $query = "UPDATE unique_xml_jobs SET 
                      $field = ?, updated_at = NOW(),
                      status = 'Inprocess'
                      WHERE id = ?";
        } else {
            $query = "UPDATE unique_xml_jobs SET 
                      $field = ?, updated_at = NOW(),
                      status = 'Inprocess', job_status = 'Inprocess'
                      WHERE id = ?";
        }
        
        $stmt = $mysqli->prepare($query);
        $stmt->bind_param("si", $assign_value, $record_id);
        $stmt->execute();
        $stmt->close();

        $response['success'] = true;
        $response['message'] = 'Task assigned successfully.';
    } catch (Exception $e) {
        $response['error'] = $e->getMessage();
    }
    
    header('Content-Type: application/json');
    echo json_encode($response);
    exit;
}

function handleCompleteRequest() {
    global $mysqli, $username;
    $response = ['success' => false, 'error' => ''];
    
    $record_id = (int)$_POST['id'];
    $field = $_POST['field'];
    $seq_number = $_POST['seq_number'] ?? '';

    if (trim($seq_number) === '') {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => 'Please enter a value.']);
        exit;
    }

    // --- NEW VALIDATION: User Check & Target Number Check ---
    $val_stmt = $mysqli->prepare("SELECT * FROM unique_xml_jobs WHERE id = ?");
    $val_stmt->bind_param("i", $record_id);
    $val_stmt->execute();
    $row_data = $val_stmt->get_result()->fetch_assoc();
    $val_stmt->close();

    $current_task_val = $row_data[$field] ?? '';
    $task_parts = explode('/', $current_task_val);
    $assigned_user = trim($task_parts[0]);
    
    if ($assigned_user !== $username) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => 'Action Denied: You can only complete tasks assigned to you.']);
        exit;
    }

    $targetNum = '';
    if ($field === 'nlm_user') $targetNum = $row_data['nlm'] ?? '';
    else if ($field === 'jats_user') $targetNum = $row_data['jats'] ?? '';
    else if ($field === 'clockss_user') $targetNum = $row_data['clockss'] ?? '';
    else if ($field === 'HTML_user') $targetNum = $row_data['html'] ?? '';
    else if ($field === 'new_html_user') $targetNum = $row_data['new_html'] ?? '';
    else if ($field === 'direct_html_user') $targetNum = $row_data['direct_html'] ?? '';
    else if ($field === 'Scielo_user') $targetNum = $row_data['scielo'] ?? '';
    else if ($field === 'ebsco_user') $targetNum = $row_data['ebsco'] ?? '';
    else if ($field === 'pubmed_user') $targetNum = $row_data['pubmed'] ?? '';
    else if ($field === 'biblat_user') $targetNum = $row_data['biblat'] ?? '';
    else if ($field === 'doaj_user') $targetNum = $row_data['doaj'] ?? '';

    $targetNum = trim((string)$targetNum);
    if ($targetNum !== '' && $targetNum !== '-') {
        if (trim($seq_number) !== $targetNum) {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'error' => "Invalid value! You must enter exactly '$targetNum' to complete this task."]);
            exit;
        }
    }
    try {
        $mysqli->begin_transaction();
        $check_stmt = $mysqli->prepare("SELECT job_id FROM unique_xml_jobs WHERE id = ?");
        $check_stmt->bind_param("i", $record_id);
        $check_stmt->execute();
        $check_stmt->bind_result($xml_job_id);
        $check_stmt->fetch();
        $check_stmt->close();
        
        $new_value = $username . '/' . date('d-m') . '/' . $seq_number . ' ✓';
        $stmt = $mysqli->prepare("UPDATE unique_xml_jobs SET $field = ?, updated_at = NOW(), updated_by = ? WHERE id = ?");
        $stmt->bind_param("ssi", $new_value, $username, $record_id);
        $stmt->execute();
        $stmt->close();
        
        $check = $mysqli->prepare("SELECT Image_user, Table_user, WPContent_user, nlm_user, jats_user, clockss_user, HTML_user, new_html_user, direct_html_user, Scielo_user, ebsco_user, pubmed_user, biblat_user, doaj_user, QC_user FROM unique_xml_jobs WHERE id = ?");
        $check->bind_param("i", $record_id);
        $check->execute();
        $res = $check->get_result()->fetch_assoc();
        $check->close();

        $all_done = true;
        foreach($res as $col => $val) {
            $v = trim((string)$val);
            if ($v === 'N/A' || $v === 'NA' || $v === '-') { continue; }
            if (empty($v) || strpos($v, '✓') === false) { $all_done = false; break; }
        }

        if ($all_done) {
            $stat_check = $mysqli->query("SELECT job_status FROM unique_xml_jobs WHERE id = $record_id");
            $curr_stat = ($stat_check && $sc_row = $stat_check->fetch_assoc()) ? trim((string)$sc_row['job_status']) : '';

            if ($curr_stat === 'Rework/Correction') {
                $mysqli->query("UPDATE unique_xml_jobs SET status = 'Completed', despatched_date = IF(despatched_date IS NULL OR despatched_date < '2000-01-01', NOW(), despatched_date), updated_at = NOW() WHERE id = $record_id");
                $response['message'] = 'All tasks completed! Row is now Green.';
            } else {
                // FIX: Mark as Despatched so color turns GREEN
                $mysqli->query("UPDATE unique_xml_jobs SET status = 'Completed', job_status = 'Despatched', updated_at = NOW() WHERE id = $record_id");
                if ($xml_job_id) updateJobsTableForXMLCompletion($xml_job_id, $username);
                $response['message'] = 'All tasks completed! Row is now Green.';
            }
        } else {
            $response['message'] = 'Task saved successfully.';
        }
        
        $mysqli->commit();
        $response['success'] = true;
    } catch (Exception $e) {
        $mysqli->rollback();
        $response['error'] = $e->getMessage();
    }
    
    header('Content-Type: application/json');
    echo json_encode($response);
    exit;
}

function updateJobsTableForXMLCompletion($job_id, $username) {
    global $mysqli;
    $check_stmt = $mysqli->prepare("SELECT job_id FROM jobs WHERE job_id = ?");
    $check_stmt->bind_param("i", $job_id);
    $check_stmt->execute();
    $check_stmt->store_result();
    $job_exists = $check_stmt->num_rows > 0;
    $check_stmt->close();
    
    if ($job_exists) {
        // FIX: Added $username to bind_param because there are two '?' in the query
        $update_stmt = $mysqli->prepare("UPDATE jobs SET assigned_xml = ?, due_date_xml = CASE WHEN due_date_xml IS NULL THEN CURDATE() ELSE due_date_xml END WHERE job_id = ?");
        $update_stmt->bind_param("si", $username, $job_id); 
        $update_stmt->execute();
        $update_stmt->close();
        return true;
    }
    return false;
}

function fetchData() {
    global $mysqli, $username;
    if (ob_get_length()) ob_clean();
    header('Content-Type: application/json');

    $show_completed = isset($_GET['show_completed']) && $_GET['show_completed'] == '1';
    $search = trim($_GET['search'] ?? '');
    $filter_client = trim($_GET['client'] ?? '');
    $filter_process = trim($_GET['process'] ?? '');
    $filter_job_status = trim($_GET['job_status'] ?? '');
    $filter_date_from = trim($_GET['date_from'] ?? '');
    $filter_date_to = trim($_GET['date_to'] ?? '');
    $filter_tat = strtolower(trim($_GET['tat'] ?? ''));
    $filter_missed_tat = strtolower(trim($_GET['missed_tat'] ?? ''));
    $sort = trim($_GET['sort'] ?? 'job_date');

    $base_query = "SELECT u.*, j.assigned_xml as jobs_assigned_xml, j.process_id, j.job_date, j.mail_subject, j.word_count as jobs_word_count, 
                   COALESCE(NULLIF(c.team_client_name, ''), NULLIF(c.client_name, ''), NULLIF(u.journal_name_client, ''), '-') as client_name, 
                   j.journal_issue 
                   FROM unique_xml_jobs u 
                   LEFT JOIN jobs j ON u.job_id = j.job_id 
                   LEFT JOIN client_master c ON j.client_id = c.client_id 
                   WHERE 1=1";

    $params = [];
    $types = '';
    
    // Completely remove 'Despatched' jobs from this page so they move to the Upload Page unless Completed / Despatched is explicitly filtered or toggle is ON
    if (!$show_completed && $filter_job_status !== 'Despatched' && $filter_job_status !== 'Completed') {
        $base_query .= " AND (u.job_status IS NULL OR u.job_status != 'Despatched')";
        $base_query .= " AND (u.status IS NULL OR u.status != 'Completed')";
    }

    if (!empty($search)) {
        $base_query .= " AND (c.client_name LIKE ? OR c.team_client_name LIKE ? OR u.journal_name_client LIKE ? OR j.journal_issue LIKE ? OR u.issue LIKE ? OR u.job_id LIKE ? OR u.id LIKE ?)";
        $params = array_merge($params, ["%$search%", "%$search%", "%$search%", "%$search%", "%$search%", "%$search%", "%$search%"]);
        $types .= 'sssssss';
    }

    if (!empty($filter_client)) {
        $base_query .= " AND (c.client_name = ? OR c.team_client_name = ?)";
        $params[] = $filter_client;
        $params[] = $filter_client;
        $types .= 'ss';
    }

    $process_col_map = [
        'nlm' => 'u.nlm',
        'jats' => 'u.jats',
        'clockss' => 'u.clockss',
        'scielo' => 'u.scielo',
        'ebsco' => 'u.ebsco',
        'pubmed' => 'u.pubmed',
        'biblat' => 'u.biblat',
        'doaj' => 'u.doaj',
        'html' => 'u.html',
        'new_html' => 'u.new_html',
        'direct_html' => 'u.direct_html',
        'new html' => 'u.new_html',
        'direct html' => 'u.direct_html'
    ];
    if (!empty($filter_process)) {
        $clean_proc = strtolower(trim($filter_process));
        if (isset($process_col_map[$clean_proc])) {
            $pcol = $process_col_map[$clean_proc];
            $base_query .= " AND ($pcol IS NOT NULL AND $pcol != '' AND $pcol != '-' AND $pcol != '0')";
        }
    }

    if (!empty($filter_job_status)) {
        if (strcasecmp($filter_job_status, 'Inprocess') === 0) {
            $base_query .= " AND (u.job_status = 'Inprocess' OR u.job_status = 'In Progress' OR u.status = 'Inprocess' OR u.status = 'In Progress')";
        } else {
            $base_query .= " AND (u.job_status = ? OR u.status = ?)";
            $params[] = $filter_job_status;
            $params[] = $filter_job_status;
            $types .= "ss";
        }
    }

    if (!empty($filter_date_from)) {
        $base_query .= " AND j.job_date >= ?";
        $params[] = $filter_date_from;
        $types .= "s";
    }
    if (!empty($filter_date_to)) {
        $base_query .= " AND j.job_date <= ?";
        $params[] = $filter_date_to . ' 23:59:59';
        $types .= "s";
    }

    // Missed TAT Sort & Filter (Ascending / Descending directly by missed days count)
    if ($filter_missed_tat === 'asc' || $filter_missed_tat === 'desc') {
        $base_query .= " AND ((u.tat_to_client IS NOT NULL AND u.tat_to_client < CURDATE() AND (u.job_status != 'Despatched' OR u.job_status IS NULL)) OR LOWER(TRIM(u.missed_tat)) = 'yes')";
        $order_query = " ORDER BY DATEDIFF(CURDATE(), u.tat_to_client) " . strtoupper($filter_missed_tat);
    }
    // TAT Sort (Ascending / Descending by date)
    elseif ($filter_tat === 'asc' || $filter_tat === 'desc') {
        $order_query = " ORDER BY (CASE WHEN u.tat_to_client IS NULL THEN 1 ELSE 0 END) ASC, u.tat_to_client " . strtoupper($filter_tat);
    }
    elseif (!empty($sort)) {
        $sort_map = [
            'job_id' => 'u.job_id',
            'job_date' => 'j.job_date',
            'client_name' => 'COALESCE(NULLIF(c.team_client_name, ""), c.client_name)',
            'tat_to_client' => 'u.tat_to_client',
            'rec_date' => 'u.rec_date'
        ];
        $col = $sort_map[$sort] ?? 'j.job_date';
        $order_query = " ORDER BY $col DESC, u.job_id DESC";
    } else {
        $order_query = " ORDER BY j.job_date DESC, u.job_id DESC";
    }
    $full_query = $base_query . $order_query;
    
    if (!empty($params)) {
        $stmt = $mysqli->prepare($full_query);
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $result = $stmt->get_result();
    } else {
        $result = $mysqli->query($full_query);
    }

    // --- NEW LOGIC: Fetch names from DB to replace IDs ---
    $user_map = [];
    $u_res = $mysqli->query("SELECT employee_username, employee_name FROM employee_master");
    if ($u_res) {
        while ($u = $u_res->fetch_assoc()) {
            $user_map[$u['employee_username']] = $u['employee_name'];
        }
    }
    // -----------------------------------------------------
    
    $data =[];
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            foreach ($row as $key => $value) if ($value === null) $row[$key] = '';

            // --- NEW LOGIC: Dynamic N/A calculation exactly like UQ Process ---
            $safe_trim = function($val) { return trim((string)$val); };
            $img_na = ($safe_trim($row['instruction_to_image'])==='' || $safe_trim($row['instruction_to_image'])==='-' || strtolower($safe_trim($row['instruction_to_image']))==='no images');
            $nlm_na = ($safe_trim($row['nlm'])==='' || $safe_trim($row['nlm'])==='-');
            $jats_na = ($safe_trim($row['jats'])==='' || $safe_trim($row['jats'])==='-');
            $clockss_na = ($safe_trim($row['clockss'])==='' || $safe_trim($row['clockss'])==='-');
            $html_na = ($safe_trim($row['html'])==='' || $safe_trim($row['html'])==='-');
            $nhtml_na = ($safe_trim($row['new_html'])==='' || $safe_trim($row['new_html'])==='-');
            $dhtml_na = ($safe_trim($row['direct_html'])==='' || $safe_trim($row['direct_html'])==='-');
            $scielo_na = ($safe_trim($row['scielo'])==='' || $safe_trim($row['scielo'])==='-');
            $ebsco_na = ($safe_trim($row['ebsco'])==='' || $safe_trim($row['ebsco'])==='-');
            $pubmed_na = ($safe_trim($row['pubmed'])==='' || $safe_trim($row['pubmed'])==='-');
            $biblat_na = ($safe_trim($row['biblat'])==='' || $safe_trim($row['biblat'])==='-');
            $doaj_na = ($safe_trim($row['doaj'])==='' || $safe_trim($row['doaj'])==='-');

            // Force N/A to JSON response if data is not applicable
            if ($img_na && $safe_trim($row['Image_user']) === '') $row['Image_user'] = 'N/A';
            if ($nlm_na && $safe_trim($row['nlm_user']) === '') $row['nlm_user'] = 'N/A';
            if ($jats_na && $safe_trim($row['jats_user']) === '') $row['jats_user'] = 'N/A';
            if ($clockss_na && $safe_trim($row['clockss_user']) === '') $row['clockss_user'] = 'N/A';
            if ($html_na && $safe_trim($row['HTML_user']) === '') $row['HTML_user'] = 'N/A';
            if ($nhtml_na && $safe_trim($row['new_html_user']) === '') $row['new_html_user'] = 'N/A';
            if ($dhtml_na && $safe_trim($row['direct_html_user']) === '') $row['direct_html_user'] = 'N/A';
            if ($scielo_na && $safe_trim($row['Scielo_user']) === '') $row['Scielo_user'] = 'N/A';
            if ($ebsco_na && $safe_trim($row['ebsco_user']) === '') $row['ebsco_user'] = 'N/A';
            if ($pubmed_na && $safe_trim($row['pubmed_user']) === '') $row['pubmed_user'] = 'N/A';
            if ($biblat_na && $safe_trim($row['biblat_user']) === '') $row['biblat_user'] = 'N/A';
            if ($doaj_na && $safe_trim($row['doaj_user']) === '') $row['doaj_user'] = 'N/A';

            // --- Swap ID with FULL Name for Task Columns ---
            $cols_to_map = ['Image_user', 'Table_user', 'WPContent_user', 'nlm_user', 'jats_user', 'clockss_user', 'HTML_user', 'new_html_user', 'direct_html_user', 'Scielo_user', 'ebsco_user', 'pubmed_user', 'biblat_user', 'doaj_user', 'QC_user'];
                foreach ($cols_to_map as $c) {
                    $val = trim((string)$row[$c]);
                    $row[$c . '_raw'] = $val;   // <-- NEW LINE (raw username store panna)
                    if ($val !== '' && $val !== '-' && $val !== 'N/A' && $val !== 'NA') {
                    $parts = explode('/', $val);
                    $uname = trim($parts[0]);
                    
                    // If ID exists in map, swap it with Full Name
                    if (isset($user_map[$uname])) {
                        // Display the Full Name exactly as it is in the database
                        $parts[0] = trim($user_map[$uname]); 
                        $row[$c] = implode('/', $parts);
                    }
                }
            }
            
            $tat_status = '';
            if (!empty($row['tat_to_client'])) {
                try {
                    $tat_date = new DateTime($row['tat_to_client']);
                    $current_date = new DateTime();
                    
                    $tat_date->setTime(0, 0, 0);
                    $current_date->setTime(0, 0, 0);
                    
                    $interval = $current_date->diff($tat_date);
                    $days_diff = (int)$interval->format('%R%a');
                    
                    if ($days_diff < 0) {
                        $tat_status = 'Overdue';
                    } elseif ($days_diff === 0) {
                        $tat_status = 'Due Today';
                    } else {
                        $tat_status = $days_diff . ' days left';
                    }
                } catch (Exception $e) { 
                    $tat_status = 'N/A'; 
                }
            }
            $row['tat_status'] = $tat_status;

            $data[] = $row;
        }
    }
    echo json_encode(['data' => $data]);
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>XML Jobs Management - Flat Wide Grid</title>
    
    <!-- Bootstrap 5 & DataTables -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/dataTables.bootstrap5.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    
    <style>
        :root {
            --bg-color: #f5f7fa;
            --header-bg: #5e6b77;
        }
        
        html, body {
            background-color: var(--bg-color);
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            font-size: 12.5px;
            color: #2c3e50;
            overflow-x: hidden !important;
            max-width: 100vw;
        }
        .container-fluid {
            max-width: 100%;
            overflow-x: hidden;
        }
        .card {
            max-width: 100%;
            overflow: hidden;
        }

        /* 1. Consistent Header Styling */
        table.dataTable {
            border-collapse: separate !important;
            border-spacing: 0 !important;
            margin-top: 0 !important;
            font-size: 12.5px;
            width: 100% !important;
        }

        table.dataTable thead {
            position: static !important;
        }

        table.dataTable thead th { 
            position: sticky !important;
            top: 0 !important;
            z-index: 20 !important;
            background-color: var(--header-bg) !important; 
            color: #ffffff !important; 
            font-size: 12.5px;
            font-weight: 600;
            text-align: center;
            border-bottom: 1px solid #dee2e6 !important;
            border-right: 1px solid #dee2e6 !important;
            border-top: 1px solid #dee2e6 !important;
            padding: 8px 10px !important;
            white-space: nowrap;
        }
        table.dataTable thead th:first-child {
            border-left: 1px solid #dee2e6 !important;
        }

        /* 2. Cell Padding & Text Alignments */
        table.dataTable tbody td { 
            vertical-align: middle !important; 
            text-align: center;
            border-bottom: 1px solid #dee2e6 !important;
            border-right: 1px solid #dee2e6 !important;
            padding: 6px 10px !important;
            white-space: nowrap;
            font-size: 12.5px;
        }
        table.dataTable tbody td:first-child {
            border-left: 1px solid #dee2e6 !important;
        }

        /* 3. Zebra Striping matching UQ Process */
        table.dataTable tbody tr:nth-child(even) td {
            background-color: #f2f4f6 !important;
        }
        table.dataTable tbody tr:nth-child(odd) td {
            background-color: #ffffff !important;
        }

        /* 4. Full Row Hover Effect */
        table.dataTable tbody tr:hover td {
            background-color: #c4cbd3 !important;
            cursor: pointer;
            transition: background-color 0.1s ease;
        }

        /* 5. Sticky Columns for Unassigned Jobs with Exact Widths (No Gaps, No Overlap) */
        table.dataTable thead th:nth-child(1), table.dataTable tbody td:nth-child(1) {
            position: sticky !important; left: 0 !important;
            width: 45px !important; min-width: 45px !important; max-width: 45px !important;
            box-sizing: border-box !important;
        }
        table.dataTable thead th:nth-child(2), table.dataTable tbody td:nth-child(2) {
            position: sticky !important; left: 45px !important;
            width: 140px !important; min-width: 140px !important; max-width: 140px !important;
            box-sizing: border-box !important;
        }
        table.dataTable thead th:nth-child(3), table.dataTable tbody td:nth-child(3) {
            position: sticky !important; left: 185px !important;
            width: 135px !important; min-width: 135px !important; max-width: 135px !important;
            box-sizing: border-box !important;
        }
        table.dataTable thead th:nth-child(4), table.dataTable tbody td:nth-child(4) {
            position: sticky !important; left: 320px !important;
            width: 140px !important; min-width: 140px !important; max-width: 140px !important;
            border-right: 1px solid #dee2e6 !important;
            box-sizing: border-box !important;
        }

        /* Fixed background for sticky columns to avoid see-through/overlap */
        table.dataTable tbody tr:nth-child(even) td:nth-child(-n+4) { background-color: #f2f4f6 !important; }
        table.dataTable tbody tr:nth-child(odd) td:nth-child(-n+4) { background-color: #ffffff !important; }
        table.dataTable tbody tr:hover td:nth-child(-n+4) { background-color: #c4cbd3 !important; }

        table.dataTable tbody td:nth-child(-n+4) {
            z-index: 10 !important;
        }

        /* Apply z-index only to the first 4 headers */
        table.dataTable thead th:nth-child(-n+4) {
            position: sticky !important;
            top: 0 !important;
            z-index: 120 !important;
            background-color: var(--header-bg) !important;
            color: #ffffff !important;
        }

        /* 6. Process Raw Numbers and Badges */
        .badge-article { color: #000; font-weight: bold; font-size: 12px; }
        .badge-cyan-num { color: #000; font-weight: 700; font-size: 12px; }
        .badge-blue-status { background-color: #0d6efd; color: #fff; padding: 3px 10px; border-radius: 12px; font-weight: 700; font-size: 10px; }
        .badge-missed { background-color: #dc3545; color: #fff; padding: 4px 10px; border-radius: 20px; font-size: 11px; font-weight: bold; display: inline-block; }

        .status-inprocess { background-color: #fd7e14 !important; color: white !important; border-radius: 12px; padding: 3px 10px; font-weight: 700; font-size: 10px; }
        .status-despatched { background-color: #198754 !important; color: white !important; border-radius: 12px; padding: 3px 10px; font-weight: 700; font-size: 10px; }
        

        .form-control, .form-select, .input-group-text, .btn-sm {
            font-size: 12.5px !important;
        }
        .notification { position: fixed; top: 20px; right: 20px; z-index: 1060; min-width: 300px; }

        .table-scroll-container {
            max-height: calc(100vh - 380px);
            overflow-x: auto !important;
            overflow-y: auto !important;
            position: relative;
            border-top: 1px solid #dee2e6;
            scrollbar-color: #6c757d #f1f1f1 !important;
            scrollbar-width: thin !important;
        }
        .table-scroll-container::-webkit-scrollbar {
            width: 10px !important;
            height: 10px !important;
        }
        .table-scroll-container::-webkit-scrollbar-track {
            background: #f1f1f1 !important;
            border-radius: 4px;
        }
        .table-scroll-container::-webkit-scrollbar-thumb {
            background: #6c757d !important;
            border-radius: 4px;
        }
        .table-scroll-container::-webkit-scrollbar-thumb:hover {
            background: #5a6268 !important;
        }
    </style>

</head>
<body>
    <?php require 'header.php'; ?>


    <div id="notificationContainer" class="notification"></div>
    
    <div class="container-fluid mt-4 px-4 pb-5">
        <div class="card mb-4">
            <!-- Header -->
            <div class="card-header d-flex justify-content-between align-items-center py-3" style="background-color: #5e6b77; border-bottom: 2px solid #475569;">
                <h5 class="mb-0 fw-bold text-white"><i class="bi bi-table me-2 text-white"></i>Unassigned Jobs</h5>
                <div class="d-flex align-items-center gap-3">
                    <span class="badge bg-light text-dark border px-3 py-2 fw-medium rounded-pill">
                        <i class="bi bi-person-circle text-muted me-1"></i> <?php echo h($username); ?>
                    </span>
                    <div class="form-check form-switch mb-0">
                        <input class="form-check-input" type="checkbox" id="showCompleted" <?php echo $show_completed ? 'checked' : ''; ?> onchange="toggleShowCompleted()" style="cursor: pointer;">
                        <label class="form-check-label small fw-medium text-white" for="showCompleted" style="cursor: pointer; padding-top:2px;">Show Completed</label>
                    </div>
                </div>
            </div>
            
            <!-- Filter Bar -->
            <div class="card-body bg-white border-bottom p-3">
                <form method="get" class="row g-2 align-items-end">
                    <?php if($show_completed): ?> <input type="hidden" name="show_completed" value="1"> <?php endif; ?>
                    
                    <div class="col-md-3">
                        <input type="text" name="search" placeholder="Sort by ID/Client/Article" value="<?= h($search) ?>" class="form-control">
                    </div>
                    <div class="col-md-3">
                        <select name="client" class="form-select">
                            <option value="">Sort by Client</option>
                            <?php foreach ($page_clients as $pc): 
                                $c_name = $pc['display_client'];
                            ?>
                                <option value="<?= h($c_name) ?>" <?= ($filter_client === $c_name) ? 'selected' : '' ?>><?= h($c_name) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <select name="process" class="form-select">
                            <option value="">Sort by Process</option>
                            <option value="NLM" <?= strcasecmp($filter_process, 'NLM') === 0 ? 'selected' : '' ?>>NLM</option>
                            <option value="JATS" <?= strcasecmp($filter_process, 'JATS') === 0 ? 'selected' : '' ?>>JATS</option>
                            <option value="Clockss" <?= strcasecmp($filter_process, 'Clockss') === 0 ? 'selected' : '' ?>>Clockss</option>
                            <option value="Scielo" <?= strcasecmp($filter_process, 'Scielo') === 0 ? 'selected' : '' ?>>Scielo</option>
                            <option value="EBSCO" <?= strcasecmp($filter_process, 'EBSCO') === 0 ? 'selected' : '' ?>>EBSCO</option>
                            <option value="PubMed" <?= strcasecmp($filter_process, 'PubMed') === 0 ? 'selected' : '' ?>>PubMed</option>
                            <option value="Biblat" <?= strcasecmp($filter_process, 'Biblat') === 0 ? 'selected' : '' ?>>Biblat</option>
                            <option value="DOAJ" <?= strcasecmp($filter_process, 'DOAJ') === 0 ? 'selected' : '' ?>>DOAJ</option>
                            <option value="HTML" <?= strcasecmp($filter_process, 'HTML') === 0 ? 'selected' : '' ?>>HTML</option>
                            <option value="New HTML" <?= strcasecmp($filter_process, 'New HTML') === 0 ? 'selected' : '' ?>>New HTML</option>
                            <option value="Direct HTML" <?= strcasecmp($filter_process, 'Direct HTML') === 0 ? 'selected' : '' ?>>Direct HTML</option>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <select name="job_status" class="form-select">
                            <option value="">Filter by Job Status</option>
                            <option value="Query" <?= strcasecmp($filter_job_status, 'Query') === 0 ? 'selected' : '' ?>>Query</option>
                            <option value="Rework/Correction" <?= strcasecmp($filter_job_status, 'Rework/Correction') === 0 ? 'selected' : '' ?>>Rework/Correction</option>
                            <option value="Hold" <?= strcasecmp($filter_job_status, 'Hold') === 0 ? 'selected' : '' ?>>Hold</option>
                            <option value="Inprocess" <?= strcasecmp($filter_job_status, 'Inprocess') === 0 ? 'selected' : '' ?>>Inprocess</option>
                            <option value="Despatched" <?= strcasecmp($filter_job_status, 'Despatched') === 0 ? 'selected' : '' ?>>Despatched</option>
                            <option value="PNR" <?= strcasecmp($filter_job_status, 'PNR') === 0 ? 'selected' : '' ?>>PNR</option>
                        </select>
                    </div>

                    <div class="col-md-3">
                        <div class="input-group input-group-sm">
                            <input type="date" name="date_from" value="<?= h($filter_date_from) ?>" class="form-control" title="Date From">
                            <input type="date" name="date_to" value="<?= h($filter_date_to) ?>" class="form-control" title="Date To">
                        </div>
                    </div>
                    <div class="col-md-2">
                        <select name="tat" class="form-select">
                            <option value="">Sort by TAT</option>
                            <option value="asc" <?= $filter_tat === 'asc' ? 'selected' : '' ?>>Ascending</option>
                            <option value="desc" <?= $filter_tat === 'desc' ? 'selected' : '' ?>>Descending</option>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <select name="missed_tat" class="form-select">
                            <option value="">Missed TAT</option>
                            <option value="asc" <?= $filter_missed_tat === 'asc' ? 'selected' : '' ?>>Ascending</option>
                            <option value="desc" <?= $filter_missed_tat === 'desc' ? 'selected' : '' ?>>Descending</option>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <select name="sort" class="form-select"> 
                            <option value="job_date" <?= $sort === 'job_date' ? 'selected' : '' ?>>Sort by Job Date</option>
                            <option value="job_id" <?= $sort === 'job_id' ? 'selected' : '' ?>>Sort by Job ID</option>
                            <option value="client_name" <?= $sort === 'client_name' ? 'selected' : '' ?>>Sort by Client</option>
                            <option value="tat_to_client" <?= $sort === 'tat_to_client' ? 'selected' : '' ?>>Sort by TAT Date</option>
                            <option value="rec_date" <?= $sort === 'rec_date' ? 'selected' : '' ?>>Sort by Rec Date</option>
                        </select>
                    </div>
                    <div class="col-md-3 d-flex gap-1">
                        <button type="submit" class="btn btn-primary btn-sm flex-grow-1"><i class="bi bi-funnel me-1"></i> Apply Filters</button>
                        <a href="xml.php<?= $show_completed ? '?show_completed=1' : '' ?>" class="btn btn-outline-secondary btn-sm" title="Reset Filters"><i class="bi bi-arrow-counterclockwise"></i></a>
                    </div>
                </form>
            </div>

            <!-- Single Line Wide Table Data -->
            <div class="card-body p-0">
                <table id="xmlJobsTable" class="table table-hover w-100 mb-0">
                   <thead>
                        <tr>
                            <th>S.No</th>
                            <th>Rec Date (Time)</th>
                            <th>Client Name</th>
                            <th>Journal / Issue</th>
                            <th>No.of Article</th>
                            <th>NLM</th>
                            <th>JATS</th>
                            <th>Clockss</th>
                            <th>Scielo</th>
                            <th>EBSCO</th>
                            <th>PubMed</th>
                            <th>Biblat</th>
                            <th>DOAJ</th>
                            <th>HTML</th>
                            <th>New HTML</th>
                            <th>Direct HTML</th>
                            <th>Job Status</th>
                            <th>TAT</th>
                            <th>Missed TAT</th>
                            <th>Img Inst.</th>
                            <!-- Task Columns -->
                            <th>Image</th>
                            <th>Table</th>
                            <th>WP</th>
                            <th>NLM</th>
                            <th>JATS</th>
                            <th>Clockss</th>
                            <th>HTML</th>
                            <th>New HTML</th>
                            <th>Direct HTML</th>
                            <th>Scielo</th>
                            <th>EBSCO</th>
                            <th>PubMed</th>
                            <th>Biblat</th>
                            <th>DOAJ</th>
                            <th>QC</th>
                            <th>Despatched Date</th>
                            <th>Remarks</th>
                            <th>Action</th>
                        </tr>
                    </thead>

                </table>
            </div>
        </div>
    </div>

    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.6/js/dataTables.bootstrap5.min.js"></script>
    
    <script>
    let table;
    const currentUser = '<?php echo h($username); ?>';

    function showNotification(message, type = 'success') {
        const icon = type === 'success' ? '<i class="bi bi-check2-circle"></i>' : '<i class="bi bi-exclamation-circle"></i>';
        const bg = type === 'success' ? 'bg-success' : 'bg-danger';
        const toast = `
            <div class="toast show align-items-center text-white ${bg} border-0 mb-2 shadow" role="alert">
                <div class="d-flex">
                    <div class="toast-body fw-medium" style="font-size: 0.85rem">${icon} ${message}</div>
                    <button type="button" class="btn-close btn-close-white me-2 m-auto" onclick="$(this).closest('.toast').remove()"></button>
                </div>
            </div>`;
        $('#notificationContainer').append(toast);
        setTimeout(() => $('.toast').first().fadeOut(300, function() { $(this).remove(); }), 3500);
    }

    function toggleShowCompleted() {
        let params = new URLSearchParams(window.location.search);
        if ($('#showCompleted').is(':checked')) {
            params.set('show_completed', '1');
        } else {
            params.delete('show_completed');
        }
        window.location.search = params.toString();
    }

    $(document).ready(function() {
        let ajaxParams = new URLSearchParams(window.location.search);
        ajaxParams.set('fetch', 'data');
        let ajaxUrl = '?' + ajaxParams.toString();

        table = $('#xmlJobsTable').DataTable({
            ajax: ajaxUrl,
            autoWidth: false,
            ordering: false,
            pageLength: 25,
            dom: '<"top d-flex justify-content-between p-3"i p>r<"table-scroll-container"t>',
           columns:[
               { 
                    data: null, 
                    render: (data, type, row, meta) => `<span class="fw-bold">${meta.row + 1}</span>` 
                },
                { 
                    data: 'rec_date', 
                    render: data => {
                        if (!data) return '-';
                        let d = new Date(data);
                        const months = ["Jan", "Feb", "Mar", "Apr", "May", "Jun", "Jul", "Aug", "Sep", "Oct", "Nov", "Dec"];
                        let day = ("0" + d.getDate()).slice(-2);
                        let monthName = months[d.getMonth()];
                        let year = d.getFullYear();
                        let timePart = d.getHours().toString().padStart(2, '0') + ':' + d.getMinutes().toString().padStart(2, '0');
                        return `${day}-${monthName}-${year}-${timePart}`;
                    }
                },
                { 
                    data: 'client_name', 
                    className: "text-start fw-bold text-primary", 
                    render: data => `<div style="max-width: 115px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;" title="${data ? data.replace(/"/g, '&quot;') : ''}">${data || '-'}</div>` 
                },
                { 
                    data: 'journal_issue', 
                    className: "text-start", 
                    render: data => `<div style="max-width: 120px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;" title="${data ? data.replace(/"/g, '&quot;') : ''}">${data || '-'}</div>` 
                },
                { data: 'articles', render: data => `<span class="fw-bold text-dark" style="font-size: 12px;">${data || '-'}</span>` },
                
                // Process columns rendered as raw numbers in black text (no background, no rounded badge)
                ...['nlm', 'jats', 'clockss', 'scielo', 'ebsco', 'pubmed', 'biblat', 'doaj', 'html', 'new_html', 'direct_html'].map(field => {
                    return {
                        data: field,
                        render: function(data) {
                            let v = (data === null || data === undefined || String(data).trim() === '' || String(data).trim() === '-') ? '-' : String(data).trim();
                            if (v === '-') return `<span class="text-muted">-</span>`;
                            return `<span class="fw-bold text-dark" style="font-size: 12px;">${v}</span>`;
                        }
                    }
                }),

                { 
                    data: 'job_status', 
                    render: function(data, type, row) {
                        let statusText = data;
                        
                        if (data === 'Rework/Correction') {
                            return `<span class="badge" style="background-color: #6f42c1; color: #fff; font-weight: 700; padding: 3px 10px; border-radius: 12px; font-size: 10px;">Rework/Correction</span>`;
                        }

                        let tasks = [row.Image_user, row.Table_user, row.WPContent_user, row.nlm_user, row.jats_user, row.clockss_user, row.HTML_user, row.new_html_user, row.direct_html_user, row.Scielo_user, row.ebsco_user, row.pubmed_user, row.biblat_user, row.doaj_user, row.QC_user];
                        let isAssigned = false;
                        
                        for(let i=0; i<tasks.length; i++) {
                            let val = tasks[i] ? String(tasks[i]).trim() : '';
                            if(val !== '' && val !== '-' && val !== 'N/A' && val !== 'NA') {
                                isAssigned = true;
                                break;
                            }
                        }

                        if (!isAssigned) {
                            statusText = 'Need to Assign';
                        } else if (!data || data === '-' || String(data).trim() === '') {
                            statusText = 'Need to Assign';
                        }
                        
                        // Matches UQ Process: Inprocess = Blue, Despatched = Green
                        let colorClass = (statusText === 'Inprocess' || statusText === 'In Progress') ? 'status-inprocess' : (statusText === 'Despatched' ? 'status-despatched' : 'badge-blue-status');
                        return `<span class="badge ${colorClass}">${statusText}</span>`;
                    } 
                },
                { 
                    data: 'tat_to_client', 
                    render: data => {
                        if (!data) return '-';
                        let d = new Date(data);
                        const months = ["Jan", "Feb", "Mar", "Apr", "May", "Jun", "Jul", "Aug", "Sep", "Oct", "Nov", "Dec"];
                        let day = ("0" + d.getDate()).slice(-2);
                        let monthName = months[d.getMonth()];
                        let year = d.getFullYear();
                        return `<span class="fw-bold">${day}-${monthName}-${year}</span>`;
                    }
                },
                { 
                    data: null, 
                    render: function(data, type, row) {
                        if (!row.tat_to_client) return '<span class="text-muted">-</span>';
                        
                        let tatDate = new Date(row.tat_to_client);
                        let today = new Date();
                        
                        // Reset time to compare only dates
                        tatDate.setHours(0, 0, 0, 0);
                        today.setHours(0, 0, 0, 0);

                        // Calculate difference in days
                        let diffTime = today.getTime() - tatDate.getTime();
                        let diffDays = Math.floor(diffTime / (1000 * 60 * 60 * 24));

                        // Show badge only if overdue and not despatched
                        if (diffDays > 0 && row.job_status !== 'Despatched') {
                            return `<span class="badge-missed">${diffDays} days</span>`;
                        }
                        return '<span class="text-muted">-</span>';
                    }
                },
                { data: 'instruction_to_image', render: (data, type, row) => `<input type="text" id="instruction_${row.id}" class="form-control form-control-sm" style="min-width:120px;" value="${(data||'').replace(/"/g, '&quot;')}">` },

                // Task Columns mapping with 3-Step Flow (Full Cell Coloring - Including Blue for Assign)
                ...[
                    { field: 'Image_user' }, { field: 'Table_user' }, { field: 'WPContent_user' },
                    { field: 'nlm_user' }, { field: 'jats_user' }, { field: 'clockss_user' },
                    { field: 'HTML_user' }, { field: 'new_html_user' }, { field: 'direct_html_user' },
                    { field: 'Scielo_user' }, { field: 'ebsco_user' }, 
                    { field: 'pubmed_user' }, { field: 'biblat_user' }, { field: 'doaj_user' },
                    { field: 'QC_user' }
                ].map(task => {
                    return {
                        data: null,
                        createdCell: function(td, cellData, rowData, row, col) {
                            let val = rowData[task.field] ? String(rowData[task.field]).trim() : '';

                            // Apply full cell background colors using !important to override zebra stripes
                            if (val === 'N/A' || val === 'NA') {
                                $(td).attr('style', 'background-color: #e9ecef !important; color: #6c757d !important; vertical-align: middle !important;');
                            } else if (!val || val === '-' || val === '') {
                                // Assign State (Full Blue Cell)
                                $(td).attr('style', 'background-color: #0d6efd !important; vertical-align: middle !important;');
                            } else if (val.includes('✓')) {
                                // Completed State (Full Green Cell)
                                $(td).attr('style', 'background-color: #198754 !important; color: #ffffff !important; font-weight: 600 !important; vertical-align: middle !important;');
                            } else {
                                // Assigned to member State (Full Orange Cell)
                                $(td).attr('style', 'background-color: #fd7e14 !important; color: #ffffff !important; vertical-align: middle !important;');
                            }
                        },
                        render: function(data, type, row) {
                            let f = task.field;
                            let val = row[f] ? String(row[f]).trim() : '';

                            // 1. Not Applicable (N/A) -> Plain text since cell is grey
                            if (val === 'N/A' || val === 'NA') {
                                return `<span style="font-size:11px; font-weight: 600;">N/A</span>`;
                            }

                            // 2. Step 1: Initial Unassigned State -> Show [ Assign ] Button (White Button on Blue Cell)
                            if (!val || val === '-' || val === '') {
                                return `<button type="button" class="btn btn-light btn-sm rounded-pill px-3 py-0 shadow-sm" 
                                            style="font-size:11px; height:24px; line-height:22px; color: #0d6efd; font-weight: 700; border: none;" 
                                            onclick="assignTask(${row.id}, '${f}')">Assign</button>`;
                            }

                            // 3. Step 3: Completed State -> Plain white text with Icon (Cell is already Green)
                            if (val.includes('✓')) {
                                return `<span style="font-size: 11.5px; display: inline-flex; align-items: center; gap: 4px;">
                                            ${val}
                                        </span>`;
                            }

                            // 4. Step 2: Assigned In-Progress -> White text, input, and white check button (Cell is Orange)
                            let p = val.split('/');
                            let user = p[0] || val || currentUser;
                            let seq = p[2] ? p[2].replace('✓', '').trim() : '';

                            return `<div class="d-flex align-items-center justify-content-center gap-1">
                                        <span class="fw-semibold text-white" style="font-size:11px;">${user}</span>
                                        <input type="text" id="seq_${f}_${row.id}" class="form-control text-center px-1 shadow-none" 
                                            style="width: 38px; height: 24px; font-size: 11px; border: 1px solid #ffffff; border-radius: 4px; color: #000; background-color: #ffffff;" 
                                            placeholder="#" value="${seq}" 
                                            onkeydown="if(event.key === 'Enter') { completeTask(${row.id}, '${f}', this.value); }">
                                        <button type="button" class="btn btn-sm btn-light p-0 d-flex align-items-center justify-content-center shadow-sm" 
                                                style="width: 24px; height: 24px; border-radius: 4px; border: none; color: #fd7e14; background-color: #ffffff;" 
                                                onclick="completeTask(${row.id}, '${f}')" title="Complete Task">
                                            <i class="bi bi-check-lg" style="font-size:13px; font-weight:bold;"></i>
                                        </button>
                                    </div>`;
                        }
                    }
                }),

                { 
                    data: 'despatched_date', 
                    render: (data, type, row) => {
                        if (!data || data === '0000-00-00' || row.job_status !== 'Despatched') return '-';
                        
                        let d = new Date(data);
                        const months = ["Jan", "Feb", "Mar", "Apr", "May", "Jun", "Jul", "Aug", "Sep", "Oct", "Nov", "Dec"];
                        let day = ("0" + d.getDate()).slice(-2);
                        let monthName = months[d.getMonth()];
                        let year = d.getFullYear();
                        return `${day}-${monthName}-${year}`;
                    }
                },
                { data: 'remarks', render: (data, type, row) => `<input type="text" id="remarks_${row.id}" class="form-control form-control-sm" style="min-width:140px;" value="${(data||'').replace(/"/g, '&quot;')}">` },
                { 
                    data: null, 
                    render: (d, t, row) => `
                        <button type="button" class="btn btn-primary btn-sm px-3" onclick="saveRow(${row.id})"><i class="bi bi-save me-1"></i> Save</button>
                        <input type="hidden" id="status_${row.id}" value="${row.status || ''}">
                        <input type="hidden" id="job_status_${row.id}" value="${row.job_status || ''}">
                        <input type="hidden" id="billed_${row.id}" value="${row.Billed || ''}">
                        <input type="hidden" id="xml_status_${row.id}" value="${row.xml_status || ''}">
                        <input type="hidden" id="html_status_${row.id}" value="${row.html_status || ''}">
                        <input type="hidden" id="scielo_status_${row.id}" value="${row.scielo_ebsco_status || ''}">
                        <input type="hidden" id="pubmed_status_${row.id}" value="${row.pubmed_biblat_status || ''}">
                    `
                }
            ],
            language: { 
                search: "", 
                searchPlaceholder: "Search records...",
                info: "<span class='text-muted small'>Showing _START_ to _END_ of _TOTAL_ entries</span>",
                paginate: { previous: "<i class='bi bi-chevron-left'></i>", next: "<i class='bi bi-chevron-right'></i>" }
            }
        });

        table.on('page.dt search.dt order.dt', function () {
            $('.table-scroll-container').scrollLeft(0);
        });
    });

    function assignTask(id, field) {
        $.post('?', { action: 'assign_to_me', id: id, field: field }, function(res) {
            if(res.success) { showNotification(res.message); table.ajax.reload(null, false); } 
            else { showNotification(res.error, 'danger'); }
        }, 'json');
    }

    function completeTask(id, field, providedValue = null) {
        let seq = (providedValue !== null) ? providedValue : $(`#seq_${field}_${id}`).val();
        
        if (seq === null || seq === undefined || String(seq).trim() === "") {
            showNotification('Please enter a value.', 'danger');
            return;
        }
        
        $.ajax({
            url: '?',
            type: 'POST',
            data: { action: 'complete', id: id, field: field, seq_number: seq },
            dataType: 'json',
            success: function(res) {
                if(res.success) { 
                    showNotification(res.message); 
                    table.ajax.reload(null, false); 
                } else { 
                    showNotification(res.error, 'danger'); 
                }
            },
            error: function(xhr, status, error) {
                console.error(xhr.responseText); // Ithu console-la exact error-ah kaattum
                showNotification('Saving failed. Check console for details.', 'danger');
            }
        });
    }

    function saveRow(id) {
        let payload = {
            action: 'full_update', id: id,
            status: $(`#status_${id}`).val(), 
            job_status: $(`#job_status_${id}`).val(), 
            billed: $(`#billed_${id}`).val(),
            xml_status: $(`#xml_status_${id}`).val(), 
            html_status: $(`#html_status_${id}`).val(),
            scielo_status: $(`#scielo_status_${id}`).val(), 
            pubmed_status: $(`#pubmed_status_${id}`).val(),
            despatched_date: $(`#despatched_date_${id}`).val(),
            instruction: $(`#instruction_${id}`).val(), 
            remarks: $(`#remarks_${id}`).val(), 
            final_remarks: $(`#final_remarks_${id}`).val()
        };
        $.post('?', payload, function(res) {
            if(res.success) { showNotification(res.message); table.ajax.reload(null, false); } 
            else { showNotification(res.error, 'danger'); }
        }, 'json');
    }
    </script>
</body>
</html>
