<br><br><br>
<?php
// Start a session if one is not already active
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require 'db.php';

$emp_lookup = [];
$emp_res = $mysqli->query("SELECT employee_username, employee_name FROM employee_master");
if($emp_res) {
    while($r = $emp_res->fetch_assoc()) {
        $emp_lookup[trim($r['employee_username'])] = trim($r['employee_name']);
    }
}
// -------------------------------------------------------------------

function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES); }

function formatDateTime($datetime, $format = 'd-m-Y') {
    if (empty($datetime) || $datetime == '0000-00-00 00:00:00' || $datetime == '0000-00-00') {
        return '—';
    }
    $dateObj = DateTime::createFromFormat('Y-m-d H:i:s', $datetime);
    if (!$dateObj) {
        $dateObj = DateTime::createFromFormat('Y-m-d', $datetime);
    }
    if (!$dateObj) {
        $timestamp = strtotime($datetime);
        if ($timestamp !== false) {
            $dateObj = new DateTime();
            $dateObj->setTimestamp($timestamp);
        }
    }
    return $dateObj ? $dateObj->format($format) : h($datetime);
}

// Function to determine cell color class for user assignment
function getUserColorClass($val) {
    $v = trim((string)$val);
    
    // If marked explicitly as N/A, make it white (blank background)
    if ($v === 'N/A' || $v === 'NA') {
        return ''; 
    }
    // If applicable but Not Assigned yet -> Show Blue background
    if ($v === '' || $v === '-') {
        return 'bg-primary text-white fw-bold'; 
    }
    // If it has a checkmark or completed text, make it Green
    if (strpos($v, '✓') !== false || strpos(strtolower($v), 'done') !== false || strpos(strtolower($v), 'completed') !== false) {
        return 'bg-cell-completed'; 
    }
    // Default assigned color (Orange)
    return 'bg-cell-assigned'; 
}

function renderUserVal($val) {
    global $emp_lookup; 
    
    $v = trim((string)$val);
    
    // Explicitly handle N/A (Not Applicable)
    if ($v === 'N/A' || $v === 'NA') {
        return 'N/A';
    }
    // Applicable but not yet assigned -> Show 'Assign'
    if ($v === '' || $v === '-') {
        return 'Assign';
    }
    
    $parts = preg_split('/[\/\s]+/', $v);
    $possible_username = $parts[0];

    if (!empty($possible_username) && isset($emp_lookup[$possible_username])) {
        $name = $emp_lookup[$possible_username];
        $v = preg_replace('/^' . preg_quote($possible_username, '/') . '/', $name, $v, 1);
    }
    // --------------------------------------------------------

    return h($v);
}

// Detect current page for menu highlighting
$currentPage  = basename($_SERVER['PHP_SELF']);
$username     = htmlspecialchars($_SESSION['username'] ?? 'User');
$current_user = $_SESSION['username'] ?? '';
$employee_id  = $_SESSION['uid'] ?? 0; // set at login

// Step 1: Fetch employee process IDs (Determine if TL/Admin)
$sql = "SELECT * FROM employee_master WHERE employee_id = ?";
$stmt = $mysqli->prepare($sql);
$stmt->bind_param("i", $employee_id);
$stmt->execute();
$result = $stmt->get_result();

$tl = false;
$team = false;

if ($row = $result->fetch_assoc()) {
    $tl = $row['user_role_id'];
    $team = $row['team_id'];
}
$stmt->close();

// ONLY enable assignment if the role ID equals 3
$can_assign = ($tl == 3);

// Fetch Team Members from employee_master for the Assignment Dropdown (team_id = 3)
$tm_users = [];
$user_query = "SELECT employee_name, employee_username FROM employee_master WHERE team_id = 3 ORDER BY employee_name ASC";
$user_result = $mysqli->query($user_query);
if ($user_result) {
    while ($r = $user_result->fetch_assoc()) {
        $tm_users[] = [
            'name' => $r['employee_name'],
            'username' => $r['employee_username']
        ];
    }
}

// Fetch clients for the Add Job dropdown
$clients = [];
$client_query = "SELECT client_id, client_name, team_client_name FROM client_master ORDER BY team_client_name";
$client_result = $mysqli->query($client_query);
if ($client_result) {
    while ($row = $client_result->fetch_assoc()) {
        $clients[] = $row;
    }
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'save_job') {
        $job_id = isset($_POST['job_id']) && $_POST['job_id'] !== '' ? (int)$_POST['job_id'] : 0;
        
        $rec_date_raw = $_POST['rec_date'] ?? '';
        $rec_date = !empty($rec_date_raw) ? date('Y-m-d H:i:s', strtotime($rec_date_raw)) : null;
        
        $client_id = (int)($_POST['client_id'] ?? 0);
        $journal = trim($_POST['journal'] ?? '');
        $issue = trim($_POST['issue'] ?? '');
        $no_articles = trim($_POST['no_articles'] ?? '');
        
        $nlm = trim($_POST['nlm'] ?? '');
        $jats = trim($_POST['jats'] ?? '');
        $clockss = trim($_POST['clockss'] ?? '');
        $scielo = trim($_POST['scielo'] ?? '');
        $ebsco = trim($_POST['ebsco'] ?? '');
        $pubmed = trim($_POST['pubmed'] ?? '');
        $biblat = trim($_POST['biblat'] ?? '');
        $doaj = trim($_POST['doaj'] ?? '');
        $html = trim($_POST['html'] ?? '');
        $new_html = trim($_POST['new_html'] ?? '');
        $direct_html = trim($_POST['direct_html'] ?? '');
        
        $tat_to_client_raw = trim($_POST['tat_to_client'] ?? '');
        $tat_to_client = !empty($tat_to_client_raw) ? date('Y-m-d', strtotime($tat_to_client_raw)) : null;
        
        $despatched_date_raw = trim($_POST['despatched_date'] ?? '');
        $despatched_date = !empty($despatched_date_raw) ? date('Y-m-d H:i:s', strtotime($despatched_date_raw)) : null;
        
        // $missed_tat = trim($_POST['missed_tat'] ?? '');
        // $job_status = trim($_POST['job_status'] ?? ''); 
        // $inst_to_image = trim($_POST['inst_to_image'] ?? '');
        $missed_tat = trim($_POST['missed_tat'] ?? '');
        $job_status = trim($_POST['job_status'] ?? ''); 
        
        // --- 1. AUTOMATIC DISPATCH & COMPLETION LOGIC ---
        // Helper function to check if a task string indicates completion
        $isCompleted = function($val) {
            $v = strtolower(trim((string)$val));
            return (strpos($v, '✓') !== false || strpos($v, 'done') !== false || strpos($v, 'completed') !== false);
        };

        // If explicitly set to Despatched or if Despatch Date is entered, sync both
        if ($job_status === 'Despatched' && empty($despatched_date)) {
            $despatched_date = date('Y-m-d H:i:s');
        } elseif (!empty($despatched_date) && empty($job_status)) {
            $job_status = 'Despatched';
        }

        $inst_to_image = trim($_POST['inst_to_image'] ?? '');
        $remarks = trim($_POST['remarks'] ?? '');

        $job_date = !empty($rec_date) ? date('Y-m-d', strtotime($rec_date)) : date('Y-m-d');
        $journal_issue_combined = trim($journal . ' ' . $issue);

        $mysqli->begin_transaction();
        try {
            if ($job_id === 0) {
                // ADD NEW JOB
                $process_name = 'XML';
                $proc_sql = "SELECT job_id FROM job_master WHERE job_name = ?";
                $stmt_proc = $mysqli->prepare($proc_sql);
                $stmt_proc->bind_param("s", $process_name);
                $stmt_proc->execute();
                $proc_res = $stmt_proc->get_result();
                
                if ($proc_res->num_rows > 0) {
                    $process_id = $proc_res->fetch_assoc()['job_id'];
                } else {
                    $stmt_insert_proc = $mysqli->prepare("INSERT INTO job_master (job_name) VALUES (?)");
                    $stmt_insert_proc->bind_param("s", $process_name);
                    $stmt_insert_proc->execute();
                    $process_id = $stmt_insert_proc->insert_id;
                    $stmt_insert_proc->close();
                }
                $stmt_proc->close();

                // Insert into jobs (syncing article count and dispatch_date)
                $sql = "INSERT INTO jobs (client_id, process_id, job_date, journal_issue, article, dispatch_date) VALUES (?, ?, ?, ?, ?, ?)";
                $stmt = $mysqli->prepare($sql);
                $stmt->bind_param("iissss", $client_id, $process_id, $job_date, $journal_issue_combined, $no_articles, $despatched_date);
                $stmt->execute();
                $job_id = $stmt->insert_id;
                $stmt->close();
                
                $sql_track = "INSERT INTO unique_xml_jobs (
                    job_id, rec_date, journal_name_client, issue, articles, 
                    nlm, jats, clockss, scielo, ebsco, pubmed, biblat, doaj, 
                    html, new_html, direct_html, 
                    tat_to_client, missed_tat, instruction_to_image, remarks, job_status, despatched_date, created_by
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
                
                $stmt_t = $mysqli->prepare($sql_track);
                $stmt_t->bind_param("issssssssssssssssssssss", $job_id, $rec_date, $journal, $issue, $no_articles, $nlm, $jats, $clockss, $scielo, $ebsco, $pubmed, $biblat, $doaj, $html, $new_html, $direct_html, $tat_to_client, $missed_tat, $inst_to_image, $remarks, $job_status, $despatched_date, $current_user);
                $stmt_t->execute();
                $stmt_t->close();
                
                $message = "New XML Job added successfully!";
            } else {
                // EDIT EXISTING JOB - Sync both article count and dispatch_date into jobs
                $sql = "UPDATE jobs SET client_id = ?, job_date = ?, journal_issue = ?, article = ?, dispatch_date = ? WHERE job_id = ?";
                $stmt = $mysqli->prepare($sql);
                $stmt->bind_param("issssi", $client_id, $job_date, $journal_issue_combined, $no_articles, $despatched_date, $job_id);
                $stmt->execute();
                $stmt->close();
                
                $check_sql = "SELECT id FROM unique_xml_jobs WHERE job_id = ?";
                $check_stmt = $mysqli->prepare($check_sql);
                $check_stmt->bind_param("i", $job_id);
                $check_stmt->execute();
                $has_tracking = $check_stmt->get_result()->num_rows > 0;
                $check_stmt->close();
                
                if ($has_tracking) {
                    $sql_track = "UPDATE unique_xml_jobs SET 
                            rec_date = ?, journal_name_client = ?, issue = ?, articles = ?,
                            nlm = ?, jats = ?, clockss = ?, scielo = ?, ebsco = ?, pubmed = ?, biblat = ?, doaj = ?, 
                            html = ?, new_html = ?, direct_html = ?, 
                            tat_to_client = ?, missed_tat = ?, instruction_to_image = ?, remarks = ?, job_status = ?, despatched_date = ?,
                            updated_at = NOW(), updated_by = ? 
                            WHERE job_id = ?";
                    $stmt_t = $mysqli->prepare($sql_track);
                    $stmt_t->bind_param("ssssssssssssssssssssssi", $rec_date, $journal, $issue, $no_articles, $nlm, $jats, $clockss, $scielo, $ebsco, $pubmed, $biblat, $doaj, $html, $new_html, $direct_html, $tat_to_client, $missed_tat, $inst_to_image, $remarks, $job_status, $despatched_date, $current_user, $job_id);
                    $stmt_t->execute();
                    $stmt_t->close();
                } else {
                    $sql_track = "INSERT INTO unique_xml_jobs (
                        job_id, rec_date, journal_name_client, issue, articles, 
                        nlm, jats, clockss, scielo, ebsco, pubmed, biblat, doaj, 
                        html, new_html, direct_html, 
                        tat_to_client, missed_tat, instruction_to_image, remarks, job_status, despatched_date, created_by
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
                    $stmt_t = $mysqli->prepare($sql_track);
                    $stmt_t->bind_param("issssssssssssssssssssss", $job_id, $rec_date, $journal, $issue, $no_articles, $nlm, $jats, $clockss, $scielo, $ebsco, $pubmed, $biblat, $doaj, $html, $new_html, $direct_html, $tat_to_client, $missed_tat, $inst_to_image, $remarks, $job_status, $despatched_date, $current_user);
                    $stmt_t->execute();
                    $stmt_t->close();
                }
                $message = "XML Job updated successfully!";
            }
            $mysqli->commit();
            $message_type = "success";
        } catch (Exception $e) {
            $mysqli->rollback();
            $message = "Error saving job: " . $e->getMessage();
            $message_type = "danger";
        }
    } 
    // Assign Job POST logic
    elseif ($action === 'assign_job') {
        $job_id = isset($_POST['job_id']) ? (int)$_POST['job_id'] : 0;
        
        $img_u    = trim($_POST['image_user'] ?? '');
        $tbl_u    = trim($_POST['table_user'] ?? '');
        $wp_u     = trim($_POST['wp_user'] ?? '');
        $nlm_u     = trim($_POST['nlm_user'] ?? '');
        $jats_u    = trim($_POST['jats_user'] ?? '');
        $clockss_u = trim($_POST['clockss_user'] ?? '');
        $html_u    = trim($_POST['html_user'] ?? '');
        $nhtml_u   = trim($_POST['new_html_user'] ?? '');
        $dhtml_u   = trim($_POST['direct_html_user'] ?? '');
        $scielo_u  = trim($_POST['scielo_user'] ?? '');
        $ebsco_u   = trim($_POST['ebsco_user'] ?? '');
        $pubmed_u  = trim($_POST['pubmed_user'] ?? '');
        $biblat_u  = trim($_POST['biblat_user'] ?? '');
        $doaj_u    = trim($_POST['doaj_user'] ?? '');
        $qc_u      = trim($_POST['qc_user'] ?? '');

        if ($job_id > 0) {
            // Check if QC or all assignments are completed
            $all_done = (strpos($qc_u, '✓') !== false);
            $new_status = $all_done ? 'Despatched' : 'Inprocess';
            $auto_dispatch = $all_done ? date('Y-m-d H:i:s') : null;

            $upd_sql = "UPDATE unique_xml_jobs SET 
                        Image_user=?, Table_user=?, WPContent_user=?, 
                        nlm_user=?, jats_user=?, clockss_user=?, HTML_user=?, new_html_user=?, direct_html_user=?,
                        Scielo_user=?, ebsco_user=?, pubmed_user=?, biblat_user=?, doaj_user=?, QC_user=?,
                        job_status=?,
                        despatched_date = IF(? IS NOT NULL, ?, despatched_date),
                        updated_at=NOW(), updated_by=? 
                        WHERE job_id=?";
            $upd_stmt = $mysqli->prepare($upd_sql);
            if ($upd_stmt) {
                $upd_stmt->bind_param("sssssssssssssssssssi", $img_u, $tbl_u, $wp_u, $nlm_u, $jats_u, $clockss_u, $html_u, $nhtml_u, $dhtml_u, $scielo_u, $ebsco_u, $pubmed_u, $biblat_u, $doaj_u, $qc_u, $new_status, $auto_dispatch, $auto_dispatch, $current_user, $job_id);
                $upd_stmt->execute();
                $upd_stmt->close();

                if ($all_done) {
                    $mysqli->query("UPDATE jobs SET dispatch_date = CURDATE() WHERE job_id = $job_id");
                }

                $message = "Tasks successfully assigned! Status updated.";
                $message_type = "success";
            } else {
                $message = "Database error assigning tasks.";
                $message_type = "danger";
            }
        }
    }
    elseif ($action === 'delete_job') {
        $job_id = isset($_POST['job_id']) ? (int)$_POST['job_id'] : 0;
        if ($job_id > 0) {
            $mysqli->begin_transaction();
            try {
                $stmt1 = $mysqli->prepare("DELETE FROM unique_xml_jobs WHERE job_id = ?");
                $stmt1->bind_param("i", $job_id);
                $stmt1->execute();
                $stmt1->close();

                $stmt2 = $mysqli->prepare("DELETE FROM jobs WHERE job_id = ?");
                $stmt2->bind_param("i", $job_id);
                $stmt2->execute();
                $stmt2->close();

                $mysqli->commit();
                $message = "Record deleted successfully!";
                $message_type = "success";
            } catch (Exception $e) {
                $mysqli->rollback();
                $message = "Error deleting record: " . $e->getMessage();
                $message_type = "danger";
            }
        }
    }
}

// Get filter & sorting parameters
$search = trim($_GET['search'] ?? '');
$filter_client = trim($_GET['client'] ?? '');
$filter_process = trim($_GET['process'] ?? '');
$filter_date_from = trim($_GET['date_from'] ?? '');
$filter_date_to = trim($_GET['date_to'] ?? '');
$show_tracked = isset($_GET['show_tracked']) ? $_GET['show_tracked'] : 'all';

$sort = $_GET['sort'] ?? 'job_date';
$dir = strtoupper($_GET['dir'] ?? 'DESC');
if (!in_array($dir,['ASC', 'DESC'], true)) $dir = 'DESC';

$sort_map =[
    'job_id' => 'j.job_id',
    'job_date' => 'j.job_date',
    'client_name' => 'c.client_name',
    'tat_to_client' => 'cjt.tat_to_client',
    'rec_date' => 'cjt.rec_date'
];
$order_by = $sort_map[$sort] ?? 'j.job_date';

$where = [];
$params =[];
$types = '';

// CRUCIAL: Only show XML based jobs
$where[] = "jm.job_name LIKE '%XML%'";

if ($search) {
    $where[] = "(c.client_name LIKE ? OR c.team_client_name LIKE ? OR j.journal_issue LIKE ? OR j.article LIKE ? OR j.job_id LIKE ?)";
    $params = array_merge($params,["%$search%", "%$search%", "%$search%", "%$search%", "%$search%"]);
    $types .= "sssss";
}
if ($filter_client) {
    $where[] = "(c.client_name LIKE ? OR c.team_client_name LIKE ?)";
    $params = array_merge($params, ["%$filter_client%", "%$filter_client%"]);
    $types .= "ss";
}
if ($filter_process) {
    $where[] = "jm.job_name = ?";
    $params[] = $filter_process;
    $types .= "s";
}
if ($filter_date_from) {
    $where[] = "j.job_date >= ?";
    $params[] = $filter_date_from;
    $types .= "s";
}
if ($filter_date_to) {
    $where[] = "j.job_date <= ?";
    $params[] = $filter_date_to . ' 23:59:59';
    $types .= "s";
}
if ($show_tracked === 'tracked') {
    $where[] = "EXISTS (SELECT 1 FROM unique_xml_jobs WHERE job_id = j.job_id)";
} elseif ($show_tracked === 'untracked') {
    $where[] = "NOT EXISTS (SELECT 1 FROM unique_xml_jobs WHERE job_id = j.job_id)";
}

$where_sql = $where ? "WHERE " . implode(" AND ", $where) : "";

// 1. First: Automatically mark jobs as Despatched when QC or processes are completed (✓ / Done)
$mysqli->query("UPDATE unique_xml_jobs u 
                JOIN jobs j ON u.job_id = j.job_id
                SET u.job_status = 'Despatched', 
                    u.despatched_date = IF(u.despatched_date IS NULL OR u.despatched_date < '2000-01-01', NOW(), u.despatched_date),
                    j.dispatch_date = IF(j.dispatch_date IS NULL OR j.dispatch_date < '2000-01-01', NOW(), j.dispatch_date)
                WHERE (u.QC_user LIKE '%✓%' OR u.QC_user LIKE '%Done%' OR u.QC_user LIKE '%Completed%')
                  AND (u.job_status != 'Despatched' OR u.despatched_date IS NULL OR u.despatched_date < '2000-01-01')");

// 2. Second: Fetch fresh, updated data for the table display
$sql = "SELECT 
    j.job_id, j.job_date, j.client_id, c.client_name, c.team_client_name, j.journal_issue, j.article,
    jm.job_name AS process, j.dispatch_date,
    cjt.rec_date, cjt.journal_name_client, cjt.issue, cjt.articles,
    cjt.nlm, cjt.jats, cjt.clockss, cjt.scielo, cjt.ebsco, cjt.pubmed, cjt.biblat, cjt.doaj,
    cjt.html, cjt.new_html, cjt.direct_html, cjt.html_status,
    cjt.tat_to_client, cjt.missed_tat, cjt.instruction_to_image, cjt.remarks, cjt.job_status, cjt.despatched_date,
    cjt.Image_user, cjt.Table_user, cjt.WPContent_user, 
    cjt.nlm_user, cjt.jats_user, cjt.clockss_user, 
    cjt.Scielo_user, cjt.ebsco_user, cjt.pubmed_user, cjt.biblat_user, cjt.doaj_user, 
    cjt.HTML_user, cjt.new_html_user, cjt.direct_html_user, cjt.QC_user,
    cjt.`final Remarks` as final_remarks, cjt.Billed, cjt.status as tracking_status
FROM jobs j
JOIN client_master c ON j.client_id = c.client_id
JOIN job_master jm ON j.process_id = jm.job_id
LEFT JOIN unique_xml_jobs cjt ON cjt.job_id = j.job_id
$where_sql
ORDER BY $order_by $dir";

$stmt = $mysqli->prepare($sql);
if ($params) $stmt->bind_param($types, ...$params);
$stmt->execute();
$results = $stmt->get_result();

$stats =[
    'total_jobs' => $results->num_rows,
    'cepagxml' => 0, 'pagxml' => 0, 'cexml' => 0, 'xml' => 0,
    'missed_tat' => 0, 'billed' => 0, 'xml_pending' => 0, 'html_pending' => 0
];

if ($results && $results->num_rows > 0) {
    while ($row = $results->fetch_assoc()) {
        if ($row['process'] == 'CE+PAG+XML') $stats['cepagxml']++;
        if ($row['process'] == 'PAG+XML') $stats['pagxml']++;
        if ($row['process'] == 'CE+XML') $stats['cexml']++;
        if ($row['process'] == 'XML') $stats['xml']++;

        if (!empty($row['rec_date'])) {
            if (strtolower(trim((string)$row['missed_tat'])) === 'yes') $stats['missed_tat']++;
            if (strtolower(trim((string)$row['Billed'])) === 'yes') $stats['billed']++;
            if (empty($row['nlm']) || strtolower(trim((string)($row['nlm'] ?? ''))) === 'pending') $stats['xml_pending']++;
            if (empty($row['html_status']) || strtolower(trim((string)($row['html_status'] ?? ''))) === 'pending') $stats['html_pending']++;
        } else {
            $stats['xml_pending']++;
        }
    }
    $results->data_seek(0);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Processes Status</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css"/>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css"/>
    <style>
              /* 1. Sticky Columns Setup (Locked Widths to prevent overlap) */
        .table th.sticky-col-1, .table td.sticky-col-1 { position: sticky; left: 0; z-index: 10; min-width: 45px; max-width: 45px; }
        .table th.sticky-col-2, .table td.sticky-col-2 { position: sticky; left: 45px; z-index: 10; min-width: 130px; max-width: 130px; }
        .table th.sticky-col-3, .table td.sticky-col-3 { position: sticky; left: 175px; z-index: 10; min-width: 200px; max-width: 200px; overflow: hidden; text-overflow: ellipsis; }
        .table th.sticky-col-4, .table td.sticky-col-4 { position: sticky; left: 375px; z-index: 10; border-right: 2px solid #a0a5ab; min-width: 180px; max-width: 180px; overflow: hidden; text-overflow: ellipsis; }

        /* 2. Header Colors (Dark Grey) */
        .table thead th.sticky-col-1, 
        .table thead th.sticky-col-2, 
        .table thead th.sticky-col-3,
        .table thead th.sticky-col-4 {
            background-color: #5e6b77 !important; color: #ffffff !important; z-index: 12;
        }

        /* 3. Zebra Striping Logic (Even Rows) */
        .table tbody tr:nth-child(even) td {
            background-color: #f2f4f6; /* Visible Grey */
        }
        /* Zebra Striping Logic (Odd Rows) */
        .table tbody tr:nth-child(odd) td {
            background-color: #ffffff; /* White */
        }

        /* 4. FULL ROW HOVER EFFECT (Consistent with Upload Sheet) */
        .table tbody tr:hover td {
            background-color: #c4cbd3 !important; /* Row full-ah highlight aagum */
            cursor: pointer;
            transition: background-color 0.1s ease;
        }

        /* 5. Base Table Cell Padding & Alignment */
        .table td { vertical-align: middle; padding: 6px 10px; font-size: 12.5px; border: 1px solid #dee2e6; text-align: center; }

        /* Highlight the sticky columns too when hovering */
        .table tbody tr:hover td.sticky-col-1,
        .table tbody tr:hover td.sticky-col-2,
        .table tbody tr:hover td.sticky-col-3,
        .table tbody tr:hover td.sticky-col-4 {
            background-color: #e9ecef !important;
        }

        body { background-color: #f5f7fa; font-size: 12.5px; }
        .card { border: none; border-radius: 8px; box-shadow: 0 1px 10px rgba(0,0,0,0.05); }
        
        /* Dashboard Card Styles */
        /* .filter-card { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; } */
        .filter-card { background: #5e6b77 !important; color: white; }
        .stat-card { transition: transform 0.3s; padding: 10px; border-radius: 8px; min-width: 140px;}
        .stat-card:hover { transform: translateY(-3px); }
        .report-icon { font-size: 2rem; opacity: 0.3; }
        
        /* Grid Table Styles - Full Width Wide Table */
        .table-responsive { max-height: 65vh; overflow-y: auto; overflow-x: auto; }
        .table th { background-color: #5e6b77 !important; color:  #ffffff !important; border: 1px solid #dee2e6; font-weight: 600; font-size: 12.5px; padding: 8px 10px; white-space: nowrap; position: sticky; top: 0; z-index: 10; text-align: center; }
        .table td { vertical-align: middle; padding: 6px 10px; font-size: 12.5px; border: 1px solid #dee2e6; text-align: center; white-space: nowrap; }
        
        /* Custom Colors for Assigned User Columns */
        .bg-cell-unassigned { background-color: #ffc107 !important; color: #000 !important; font-weight: bold; }
        .bg-cell-assigned { background-color: #fd7e14 !important; color: #fff !important; font-weight: bold; }
        .bg-cell-completed { background-color: #198754 !important; color: #fff !important; font-weight: bold; }
        
        /* Status Badges */
        .badge-cyan { background-color: #0dcaf0; color: #000; font-weight: 700; padding: 3px 8px; border-radius: 12px; font-size: 10px; }
        .badge-blue { background-color: #0d6efd; color: #fff; font-weight: 700; padding: 3px 10px; border-radius: 12px; font-size: 10px; }
        .badge-grey { background-color: #6c757d; color: #fff; font-weight: 700; padding: 3px 8px; border-radius: 12px; font-size: 10px; }
        /* Job Status */
        .status-inprocess { background-color: #fd7e14 !important; color: white !important; border-radius: 12px; padding: 3px 10px; font-weight: 700; font-size: 10px; }
        .status-despatched { background-color: #198754 !important; color: white !important; border-radius: 12px; padding: 3px 10px; font-weight: 700; font-size: 10px; }
        
        .modal-lg { max-width: 900px; }
        .compact-filter .form-label { font-size: 12.5px; margin-bottom: 2px; color: #6c757d; font-weight: bold; }
        .compact-filter .form-control, .compact-filter .form-select { font-size: 12.5px; padding: 4px 8px; height: 32px; }
        
        .text-truncate-custom { max-width: 150px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; display: inline-block; cursor: pointer; vertical-align: bottom; }
        .text-truncate-custom:hover { overflow: visible; white-space: normal; background: white; box-shadow: 0 0 10px rgba(0,0,0,0.1); z-index: 1000; position: absolute; border-radius: 4px; padding: 5px; text-align: left; }

        .stat-card { border-radius: 12px; box-shadow: 0 2px 8px rgba(0,0,0,0.08); transition: 0.2s ease; }
        .stat-card:hover { transform: translateY(-3px); box-shadow: 0 4px 14px rgba(0,0,0,0.12); }
        .report-icon { font-size: 18px; opacity: 0.8; }
    </style>
</head>
<body>
    <?php require 'header.php'; ?>
    
    <div class="container-fluid mt-2">
        <?php if (isset($message)): ?>
            <div class="alert alert-<?= $message_type ?> alert-dismissible fade show py-2" style="font-size: 13px;">
                <?= h($message) ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" style="padding: 8px;"></button>
            </div>
        <?php endif; ?>

        <!-- Title Header -->
        <div class="row mb-3">
            <div class="col-12">
                <div class="card filter-card py-1">
                    <div class="card-body py-2 d-flex justify-content-between align-items-center">
                        <div>
                            <h5 class="card-title mb-0 text-white" style="font-size: 16px;">
                                <i class="fas fa-clipboard-check me-2"></i>Process Status
                            </h5>
                        </div>
                        <div>
                            <button type="button" class="btn btn-light btn-sm fw-bold shadow-sm me-2" onclick="openJobModal()">
                                <i class="fas fa-plus-circle me-1"></i> Add New Job
                            </button>
                            <a href="?" class="btn btn-secondary btn-sm"><i class="fas fa-sync-alt"></i> Refresh</a>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="row mb-3 g-2 flex-nowrap overflow-auto pb-1">
            <div class="col">
                <div class="card stat-card text-white h-100" style="background:#2C3E50;">
                    <div class="card-body p-2 d-flex justify-content-between align-items-center">
                        <div>
                            <h6 class="mb-0 text-uppercase small">Total Records</h6>
                            <h4 class="mb-0 fw-bold"><?= $stats['total_jobs'] ?></h4>
                        </div>
                        <i class="fas fa-tasks report-icon"></i>
                    </div>
                </div>
            </div>
            <div class="col">
                <div class="card stat-card text-white h-100" style="background:#27AE60;">
                    <div class="card-body p-2 d-flex justify-content-between align-items-center">
                        <div>
                            <h6 class="mb-0 text-uppercase small">CE+TS+XML</h6>
                            <h4 class="mb-0 fw-bold"><?= $stats['cepagxml'] ?></h4>
                        </div>
                        <i class="fas fa-layer-group report-icon"></i>
                    </div>
                </div>
            </div>
            <div class="col">
                <div class="card stat-card text-white h-100" style="background:#3498DB;">
                    <div class="card-body p-2 d-flex justify-content-between align-items-center">
                        <div>
                            <h6 class="mb-0 text-uppercase small">TS+XML</h6>
                            <h4 class="mb-0 fw-bold"><?= $stats['pagxml'] ?></h4>
                        </div>
                        <i class="fas fa-code report-icon"></i>
                    </div>
                </div>
            </div>
            <div class="col">
                <div class="card stat-card text-white h-100" style="background:#F39C12;">
                    <div class="card-body p-2 d-flex justify-content-between align-items-center">
                        <div>
                            <h6 class="mb-0 text-uppercase small">CE+XML</h6>
                            <h4 class="mb-0 fw-bold"><?= $stats['cexml'] ?></h4>
                        </div>
                        <i class="fas fa-file-alt report-icon"></i>
                    </div>
                </div>
            </div>
            <div class="col">
                <div class="card stat-card text-white h-100" style="background:#7F8C8D;">
                    <div class="card-body p-2 d-flex justify-content-between align-items-center">
                        <div>
                            <h6 class="mb-0 text-uppercase small">Standalone XML</h6>
                            <h4 class="mb-0 fw-bold"><?= $stats['xml'] ?></h4>
                        </div>
                        <i class="fas fa-file-code report-icon"></i>
                    </div>
                </div>
            </div>
            <div class="col">
                <div class="card stat-card text-white h-100" style="background:<?= $stats['missed_tat'] > 0 ? '#E74C3C' : '#27AE60' ?>;">
                    <div class="card-body p-2 d-flex justify-content-between align-items-center">
                        <div>
                            <h6 class="mb-0 text-uppercase small">Missed TAT</h6>
                            <h4 class="mb-0 fw-bold"><?= $stats['missed_tat'] ?></h4>
                        </div>
                        <i class="fas fa-clock report-icon"></i>
                    </div>
                </div>
            </div>
            <div class="col">
                <div class="card stat-card text-white h-100" style="background:#D35400;">
                    <div class="card-body p-2 d-flex justify-content-between align-items-center">
                        <div>
                            <h6 class="mb-0 text-uppercase small">Pending XML</h6>
                            <h4 class="mb-0 fw-bold"><?= $stats['xml_pending'] ?></h4>
                        </div>
                        <i class="fas fa-laptop-code report-icon"></i>
                    </div>
                </div>
            </div>
            <div class="col">
                <div class="card stat-card text-white h-100" style="background:#1B2631;">
                    <div class="card-body p-2 d-flex justify-content-between align-items-center">
                        <div>
                            <h6 class="mb-0 text-uppercase small">Billed Jobs</h6>
                            <h4 class="mb-0 fw-bold"><?= $stats['billed'] ?></h4>
                        </div>
                        <i class="fas fa-file-invoice-dollar report-icon"></i>
                    </div>
                </div>
            </div>
            <div class="col">
                <div class="card stat-card h-100 border" style="background:#F8F9FA;">
                    <div class="card-body p-2 d-flex justify-content-between align-items-center">
                        <div>
                            <h6 class="mb-0 text-uppercase small text-muted">Pending HTML</h6>
                            <h4 class="mb-0 fw-bold text-dark"><?= $stats['html_pending'] ?></h4>
                        </div>
                        <i class="fas fa-globe report-icon text-muted"></i>
                    </div>
                </div>
            </div>
        </div>

        <!-- Search & Filter Bar -->
        <div class="card mb-3 shadow-sm border">
            <div class="card-body p-2">
                <form method="get" class="row g-2 compact-filter align-items-end">
                    <div class="col-md-2">
                        <input type="text" name="search" placeholder="Sort by ID/Client/Article" value="<?= h($search) ?>" class="form-control">
                    </div>
                    <div class="col-md-1">
                        <input type="text" name="client" placeholder="Sort by Client" value="<?= h($filter_client) ?>" class="form-control">
                    </div>
                    <div class="col-md-1">
                        <select name="process" class="form-select">
                            <option value="">Sort by Process</option>
                            <option value="CE+PAG+XML" <?= $filter_process == 'CE+PAG+XML' ? 'selected' : '' ?>>CE+TS+XML</option>
                            <option value="PAG+XML" <?= $filter_process == 'PAG+XML' ? 'selected' : '' ?>>TS+XML</option>
                            <option value="CE+XML" <?= $filter_process == 'CE+XML' ? 'selected' : '' ?>>CE+XML</option>
                            <option value="XML" <?= $filter_process == 'XML' ? 'selected' : '' ?>>XML</option>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <div class="input-group input-group-sm">
                            <input type="date" name="date_from" value="<?= h($filter_date_from) ?>" class="form-control">
                            <input type="date" name="date_to" value="<?= h($filter_date_to) ?>" class="form-control">
                        </div>
                    </div>
                    <div class="col-md-1">
                        <select name="show_tracked" class="form-select">
                            <option value="all" <?= $show_tracked == 'all' ? 'selected' : '' ?>>Sort by Tracking</option>
                            <option value="tracked" <?= $show_tracked == 'tracked' ? 'selected' : '' ?>>Tracked</option>
                            <option value="untracked" <?= $show_tracked == 'untracked' ? 'selected' : '' ?>>Untracked</option>
                        </select>
                    </div>
                    <div class="col-md-1">
                        <select name="sort" class="form-select"> 
                            <option value="job_date" <?= $sort === 'job_date' ? 'selected' : '' ?>>Sort by Job Date</option>
                            <option value="job_id" <?= $sort === 'job_id' ? 'selected' : '' ?>>Sort by Job ID</option>
                            <option value="client_name" <?= $sort === 'client_name' ? 'selected' : '' ?>>Sort by Client</option>
                            <option value="tat_to_client" <?= $sort === 'tat_to_client' ? 'selected' : '' ?>>Sort by TAT Date</option>
                            <option value="rec_date" <?= $sort === 'rec_date' ? 'selected' : '' ?>>Sort by Rec Date</option>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <button type="submit" class="btn btn-primary btn-sm w-100"><i class="fas fa-filter me-1"></i> Apply Filters</button>
                    </div>
                </form>
            </div>
        </div>

        <!-- Main Data Table -->
        <div class="card shadow-sm border">
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-bordered mb-0" id="trackingTable">
                        <thead>
                            <tr>
                                <th class="sticky-col-1">S.No</th>
                                <th class="sticky-col-2">Rec Date (Time)</th>
                                <th class="sticky-col-3">Client Name</th>
                                <th class="sticky-col-4">Journal / Issue</th>
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
                                <th style="position: sticky; right: 0; background: #f8f9fa; z-index: 11; min-width: 85px;">Act.</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($results->num_rows > 0): $i=0;
                                while($row = $results->fetch_assoc()): $i=$i+1; 
                                    $is_tracked = !empty($row['rec_date']);
                                    
                                    // Raw value formatted for Datetime-Local Picker
                                    $recDateRaw = (!empty($row['rec_date']) && $row['rec_date'] != '0000-00-00 00:00:00') ? date('Y-m-d\TH:i', strtotime($row['rec_date'])) : '';
                                    
                                    // Parse TAT Date for the picker
                                    $tatValRaw = $row['tat_to_client'];
                                    $tatModalVal = '';
                                    if(!empty($tatValRaw)) {
                                        $tTime = strtotime($tatValRaw);
                                        if($tTime) $tatModalVal = date('Y-m-d', $tTime);
                                    }

                                    // --- NEW LOGIC: AUTO-DISPATCH CHECK FOR ALL TASKS ---
                                    if ($is_tracked) {
                                        $user_columns_check = [
                                            $row['Image_user'], $row['Table_user'], $row['WPContent_user'], 
                                            $row['nlm_user'], $row['jats_user'], $row['clockss_user'],
                                            $row['Scielo_user'], $row['ebsco_user'], $row['pubmed_user'], 
                                            $row['biblat_user'], $row['doaj_user'],
                                            $row['HTML_user'], $row['new_html_user'], $row['direct_html_user'], $row['QC_user']
                                        ];

                                        $all_tasks_completed = true;
                                        $has_valid_task = false;

                                        foreach ($user_columns_check as $col_val) {
                                            $v = trim((string)$col_val);
                                            if ($v === 'N/A' || $v === 'NA' || $v === '-' || $v === '') {
                                                continue; 
                                            }
                                            
                                            $has_valid_task = true;
                                            if (strpos($v, '✓') === false && strpos(strtolower($v), 'done') === false && strpos(strtolower($v), 'completed') === false) {
                                                $all_tasks_completed = false;
                                                break;
                                            }
                                        }
                                        if ($has_valid_task && $all_tasks_completed && $row['job_status'] !== 'Despatched') {
                                            $row['job_status'] = 'Despatched'; 
                                            
                                            if (empty($row['despatched_date']) || $row['despatched_date'] == '0000-00-00') {
                                                $row['despatched_date'] = date('Y-m-d H:i:s'); 
                                            }
                                            
                                            $upd_id = (int)$row['job_id'];
                                            $upd_date = $row['despatched_date'];
                                            $mysqli->query("UPDATE unique_xml_jobs SET job_status = 'Despatched', despatched_date = '$upd_date' WHERE job_id = $upd_id");
                                            $mysqli->query("UPDATE jobs SET dispatch_date = '$upd_date' WHERE job_id = $upd_id");
                                        }
                                    }
                            ?>
                            <tr>
                                <td class="fw-bold sticky-col-1"><?= $i ?></td>
                                <td class="sticky-col-2"><?= $is_tracked ? formatDateTime($row['rec_date'], 'd-M-Y H:i') : '-' ?></td>
                                <td class="text-primary fw-bold text-start sticky-col-3" title="<?= h($row['team_client_name'] ?: $row['client_name']) ?>"><?= h($row['team_client_name'] ?: $row['client_name']) ?></td>
                                <td class="text-start sticky-col-4" title="<?= h($row['journal_issue']) ?>"><?= h($row['journal_issue']) ?: '-' ?></td>
                                <td><span style="background-color: #28a745; /* standard green */
    color: #fff;
    padding: 4px 8px;
    border-radius: 4px;
    font-size: 12px;"><?= h($row['article']) ?: '-' ?></span></td>
                                
                                
                                                                                            
                                <td>
                                    <?php if(!empty($row['nlm']) && $row['nlm'] !== '-'): ?>
                                        <span class="badge-cyan"><?= h($row['nlm']) ?></span>
                                    <?php else: ?><span class="text-muted">-</span><?php endif; ?>
                                </td>
                                <td>
                                    <?php if(!empty($row['jats']) && $row['jats'] !== '-'): ?>
                                        <span class="badge-cyan"><?= h($row['jats']) ?></span>
                                    <?php else: ?><span class="text-muted">-</span><?php endif; ?>
                                </td>
                                <td>
                                    <?php if(!empty($row['clockss']) && $row['clockss'] !== '-'): ?>
                                        <span class="badge-cyan"><?= h($row['clockss']) ?></span>
                                    <?php else: ?><span class="text-muted">-</span><?php endif; ?>
                                </td>
                                <td>
                                    <?php if(!empty($row['scielo']) && $row['scielo'] !== '-'): ?>
                                        <span class="badge-cyan"><?= h($row['scielo']) ?></span>
                                    <?php else: ?><span class="text-muted">-</span><?php endif; ?>
                                </td>
                                <td>
                                    <?php if(!empty($row['ebsco']) && $row['ebsco'] !== '-'): ?>
                                        <span class="badge-cyan"><?= h($row['ebsco']) ?></span>
                                    <?php else: ?><span class="text-muted">-</span><?php endif; ?>
                                </td>
                                <td>
                                    <?php if(!empty($row['pubmed']) && $row['pubmed'] !== '-'): ?>
                                        <span class="badge-cyan"><?= h($row['pubmed']) ?></span>
                                    <?php else: ?><span class="text-muted">-</span><?php endif; ?>
                                </td>
                                <td>
                                    <?php if(!empty($row['biblat']) && $row['biblat'] !== '-'): ?>
                                        <span class="badge-cyan"><?= h($row['biblat']) ?></span>
                                    <?php else: ?><span class="text-muted">-</span><?php endif; ?>
                                </td>
                                <td>
                                    <?php if(!empty($row['doaj']) && $row['doaj'] !== '-'): ?>
                                        <span class="badge-cyan"><?= h($row['doaj']) ?></span>
                                    <?php else: ?><span class="text-muted">-</span><?php endif; ?>
                                </td>
                                
                                <td>
                                    <?php if(!empty($row['html']) && $row['html'] !== '-'): ?>
                                        <span class="badge-cyan"><?= h($row['html']) ?></span>
                                    <?php else: ?><span class="text-muted">-</span><?php endif; ?>
                                </td>
                                <td>
                                    <?php if(!empty($row['new_html']) && $row['new_html'] !== '-'): ?>
                                        <span class="badge-cyan"><?= h($row['new_html']) ?></span>
                                    <?php else: ?><span class="text-muted">-</span><?php endif; ?>
                                </td>
                                <td>
                                    <?php if(!empty($row['direct_html']) && $row['direct_html'] !== '-'): ?>
                                        <span class="badge-cyan"><?= h($row['direct_html']) ?></span>
                                    <?php else: ?><span class="text-muted">-</span><?php endif; ?>
                                </td>  
                                <td>
                                    <?php 
                                    // Status check pandrom
                                    $j_stat = ($is_tracked && !empty($row['job_status'])) ? h(trim((string)$row['job_status'])) : '';
                                    
                                    // Empty-ah illana dash (-) nu iruntha "Need to Assign" nu set pandrom
                                    if ($j_stat === '' || $j_stat === '-') {
                                        $j_stat = 'Need to Assign';
                                    }
                                    
                                    $badge_class = 'badge-blue'; // Default color (Blue for Need to Assign)
                                    
                                    if (strtolower($j_stat) === 'inprocess' || strtolower($j_stat) === 'in progress') {
                                        $badge_class = 'status-inprocess'; // Orange
                                    } elseif (strtolower($j_stat) === 'despatched') {
                                        $badge_class = 'status-despatched'; // Green
                                    }
                                    
                                    echo "<span class=\"{$badge_class}\">{$j_stat}</span>";
                                    ?>
                                </td>
                                <td class="fw-bold"><?= $is_tracked && !empty($row['tat_to_client']) ? formatDateTime($row['tat_to_client'], 'd-M-Y') : '-' ?></td>
                                <!-- <td><?= $is_tracked && !empty($row['missed_tat']) ? h($row['missed_tat']) : '-' ?></td> -->
                                 <!-- <td>
    <?php 
    if ($is_tracked && !empty($row['tat_to_client'])) {
        $today_ts = strtotime(date('Y-m-d'));
        $tat_ts = strtotime($row['tat_to_client']);
        
        // Logic: If today is past TAT date AND job is not yet Despatched
        if ($today_ts > $tat_ts && $row['job_status'] !== 'Despatched') {
            echo '<span class="badge bg-danger">Yes</span>';
            
            // Optional: Automatically update DB if you want to store 'Yes'
            // $mysqli->query("UPDATE unique_xml_jobs SET missed_tat='Yes' WHERE job_id=".$row['job_id']);
        } else {
            // Show existing value or No
            echo !empty($row['missed_tat']) ? h($row['missed_tat']) : '-';
        }
    } else {
        echo '-';
    }
    ?>
</td> -->

<td>
    <?php 
    if ($is_tracked && !empty($row['tat_to_client'])) {
        $today_ts = strtotime(date('Y-m-d'));
        $tat_ts = strtotime($row['tat_to_client']);
        
        // If today is past TAT date AND job is not yet Despatched
        if ($today_ts > $tat_ts && $row['job_status'] !== 'Despatched') {
            $diff = $today_ts - $tat_ts;
            $days = floor($diff / (60 * 60 * 24)); // Convert seconds to days
            $dayText = ($days > 1) ? " days" : " day";
            echo '<span class="badge bg-danger">' . $days . $dayText . '</span>';
        } else {
            echo !empty($row['missed_tat']) ? h($row['missed_tat']) : '-';
        }
    } else {
        echo '-';
    }
    ?>
</td>
                               
                                <td><?= h($row['instruction_to_image']) ?: '-' ?></td>
                              
                                <!-- Exact Columns Assigned Users -->
                                <?php if($is_tracked): 
                                    // Helper function to safely trim null values from DB
                                    $safe_trim = function($val) { return trim((string)$val); };

                                    // 1. Dependency Checks (Safe handling for NULL)
                                    $img_na = ($safe_trim($row['instruction_to_image'])==='' || $safe_trim($row['instruction_to_image'])==='-' || strtolower($safe_trim($row['instruction_to_image']))==='no images');
                                    $nlm_na = ($safe_trim($row['nlm'] ?? '')==='' || $safe_trim($row['nlm'] ?? '')==='-');
                                    $jats_na = ($safe_trim($row['jats'] ?? '')==='' || $safe_trim($row['jats'] ?? '')==='-');
                                    $clockss_na = ($safe_trim($row['clockss'] ?? '')==='' || $safe_trim($row['clockss'] ?? '')==='-');

                                    $html_na = ($safe_trim($row['html'])==='' || $safe_trim($row['html'])==='-');
                                    $nhtml_na = ($safe_trim($row['new_html'])==='' || $safe_trim($row['new_html'])==='-');
                                    $dhtml_na = ($safe_trim($row['direct_html'])==='' || $safe_trim($row['direct_html'])==='-');

                                    $scielo_na = ($safe_trim($row['scielo'] ?? '')==='' || $safe_trim($row['scielo'] ?? '')==='-');
                                    $ebsco_na = ($safe_trim($row['ebsco'] ?? '')==='' || $safe_trim($row['ebsco'] ?? '')==='-');

                                    $pubmed_na = ($safe_trim($row['pubmed'] ?? '')==='' || $safe_trim($row['pubmed'] ?? '')==='-');
                                    $biblat_na = ($safe_trim($row['biblat'] ?? '')==='' || $safe_trim($row['biblat'] ?? '')==='-');
                                    $doaj_na = ($safe_trim($row['doaj'] ?? '')==='' || $safe_trim($row['doaj'] ?? '')==='-');

                                    // 2. Format final value Helper
                                    $is_empty = function($val) use ($safe_trim) { 
                                        $v = $safe_trim($val); 
                                        return ($v === '' || $v === 'N/A' || $v === 'NA'); 
                                    };

                                    $valImg = $img_na ? 'N/A' : ($is_empty($row['Image_user']) ? '-' : $row['Image_user']);
                                    $valTbl = $is_empty($row['Table_user']) ? '-' : $row['Table_user'];
                                    $valWp = $is_empty($row['WPContent_user']) ? '-' : $row['WPContent_user'];

                                    $valNlm = $nlm_na ? 'N/A' : ($is_empty($row['nlm_user'] ?? '') ? '-' : $row['nlm_user']);
                                    $valJats = $jats_na ? 'N/A' : ($is_empty($row['jats_user'] ?? '') ? '-' : ($row['jats_user'] ?? ''));
                                    $valClockss = $clockss_na ? 'N/A' : ($is_empty($row['clockss_user'] ?? '') ? '-' : ($row['clockss_user'] ?? ''));

                                    $valHtml = $html_na ? 'N/A' : ($is_empty($row['HTML_user']) ? '-' : $row['HTML_user']);
                                    $valNhtml = $nhtml_na ? 'N/A' : ($is_empty($row['new_html_user']) ? '-' : $row['new_html_user']);
                                    $valDhtml = $dhtml_na ? 'N/A' : ($is_empty($row['direct_html_user'] ?? '') ? '-' : ($row['direct_html_user'] ?? ''));

                                    $valScielo = $scielo_na ? 'N/A' : ($is_empty($row['Scielo_user'] ?? '') ? '-' : ($row['Scielo_user'] ?? ''));
                                    $valEbsco = $ebsco_na ? 'N/A' : ($is_empty($row['ebsco_user'] ?? '') ? '-' : ($row['ebsco_user'] ?? ''));

                                    $valPubmed = $pubmed_na ? 'N/A' : ($is_empty($row['pubmed_user'] ?? '') ? '-' : ($row['pubmed_user'] ?? ''));
                                    $valBiblat = $biblat_na ? 'N/A' : ($is_empty($row['biblat_user'] ?? '') ? '-' : ($row['biblat_user'] ?? ''));
                                    $valDoaj = $doaj_na ? 'N/A' : ($is_empty($row['doaj_user'] ?? '') ? '-' : ($row['doaj_user'] ?? ''));

                                    $valQc = $is_empty($row['QC_user']) ? '-' : $row['QC_user'];
                                ?>
                                    <td class="<?= getUserColorClass($valImg) ?>"><?= renderUserVal($valImg) ?></td>
                                    <td class="<?= getUserColorClass($valTbl) ?>"><?= renderUserVal($valTbl) ?></td>
                                    <td class="<?= getUserColorClass($valWp) ?>"><?= renderUserVal($valWp) ?></td>
                                    
                                    <td class="<?= getUserColorClass($valNlm) ?>"><?= renderUserVal($valNlm) ?></td>
                                    <td class="<?= getUserColorClass($valJats) ?>"><?= renderUserVal($valJats) ?></td>
                                    <td class="<?= getUserColorClass($valClockss) ?>"><?= renderUserVal($valClockss) ?></td>

                                    <td class="<?= getUserColorClass($valHtml) ?>"><?= renderUserVal($valHtml) ?></td>
                                    <td class="<?= getUserColorClass($valNhtml) ?>"><?= renderUserVal($valNhtml) ?></td>
                                    <td class="<?= getUserColorClass($valDhtml) ?>"><?= renderUserVal($valDhtml) ?></td>

                                    <td class="<?= getUserColorClass($valScielo) ?>"><?= renderUserVal($valScielo) ?></td>
                                    <td class="<?= getUserColorClass($valEbsco) ?>"><?= renderUserVal($valEbsco) ?></td>

                                    <td class="<?= getUserColorClass($valPubmed) ?>"><?= renderUserVal($valPubmed) ?></td>
                                    <td class="<?= getUserColorClass($valBiblat) ?>"><?= renderUserVal($valBiblat) ?></td>
                                    <td class="<?= getUserColorClass($valDoaj) ?>"><?= renderUserVal($valDoaj) ?></td>

                                    <td class="<?= getUserColorClass($valQc) ?>"><?= renderUserVal($valQc) ?></td>
                                <?php else: ?>
                                    <td colspan="15" class="bg-cell-unassigned text-center">-</td>
                                <?php endif; ?>
                                
                                <td>
                                        <?php 
                                        // Check all assigned columns in this row
                                        $user_columns = [
                                            $row['Image_user'], 
                                            $row['Table_user'], 
                                            $row['WPContent_user'], 
                                            $row['nlm_user'], 
                                            $row['HTML_user'], 
                                            $row['new_html_user'], 
                                            $row['Scielo_user'], 
                                            $row['pubmed_user'], 
                                            $row['QC_user']
                                        ];

                                        $has_orange_assigned = false;
                                        $has_any_completed   = false;

                                        foreach ($user_columns as $col_val) {
                                            $color = getUserColorClass($col_val);
                                            
                                            // If any task is still in Orange (In-Progress)
                                            if ($color === 'bg-cell-assigned') {
                                                $has_orange_assigned = true;
                                                break;
                                            }
                                            
                                            if ($color === 'bg-cell-completed') {
                                                $has_any_completed = true;
                                            }
                                        }

                                        // Only show date if NO task is orange AND at least assigned tasks are green (completed)
                                        if ($is_tracked && !$has_orange_assigned && $has_any_completed && !empty($row['despatched_date']) && $row['despatched_date'] !== '0000-00-00') {
                                            echo formatDateTime($row['despatched_date'], 'd-M-Y H:i');
                                        } else {
                                            echo '-';
                                        }
                                        ?>
                                    </td>
                                <!-- <td><?= $is_tracked && !empty($row['Billed']) ? h($row['Billed']) : '-' ?></td> -->

                                <td>
                                    <?php if($is_tracked && !empty($row['remarks'])): ?>
                                        <div class="text-truncate-custom" title="<?= h($row['remarks']) ?>"><?= h($row['remarks']) ?></div>
                                    <?php else: ?>-<?php endif; ?>
                                </td>
                                
                                <td class="align-middle bg-light" style="position: sticky; right: 0; border-left: 2px solid #dee2e6;">
                                    <div class="d-flex justify-content-center gap-1">
                                        <!-- Edit Job Button -->
                                        <button class="btn btn-sm btn-primary py-0 px-2 shadow-sm" title="Edit Job"
                                            onclick="openJobModal(this)"
                                            data-job-id="<?= $row['job_id'] ?>"
                                            data-client-id="<?= $row['client_id'] ?>"
                                            data-rec-date="<?= $recDateRaw ?>"
                                            data-journal="<?= h(!empty($row['journal_issue']) ? $row['journal_issue'] : trim(($row['journal_name_client'] ?? '') . ' ' . ($row['issue'] ?? ''))) ?>"
                                            data-articles="<?= h($row['articles'] ?? '') ?>"
                                            data-nlm="<?= h($row['nlm'] ?? '') ?>"
                                            data-jats="<?= h($row['jats'] ?? '') ?>"
                                            data-clockss="<?= h($row['clockss'] ?? '') ?>"
                                            data-scielo="<?= h($row['scielo'] ?? '') ?>"
                                            data-ebsco="<?= h($row['ebsco'] ?? '') ?>"
                                            data-pubmed="<?= h($row['pubmed'] ?? '') ?>"
                                            data-biblat="<?= h($row['biblat'] ?? '') ?>"
                                            data-doaj="<?= h($row['doaj'] ?? '') ?>"
                                            data-html="<?= h($row['html'] ?? '') ?>"
                                            data-new-html="<?= h($row['new_html'] ?? '') ?>"
                                            data-direct-html="<?= h($row['direct_html'] ?? '') ?>"
                                            data-tat="<?= $tatModalVal ?>"
                                            data-missed-tat="<?= h($row['missed_tat'] ?? '') ?>"
                                            data-job-status="<?= h($row['job_status'] ?? '') ?>"
                                            data-despatched-date="<?= !empty($row['despatched_date']) ? date('Y-m-d\TH:i', strtotime($row['despatched_date'])) : '' ?>"
                                            data-instruction="<?= h($row['instruction_to_image'] ?? '') ?>"
                                            data-remarks="<?= h($row['remarks'] ?? '') ?>">
                                            <i class="fas fa-edit"></i>
                                        </button>

                                        <!-- INSERT THE DELETE BUTTON HERE -->
        <button class="btn btn-sm btn-danger py-0 px-2 shadow-sm" title="Delete Job" 
                onclick="deleteJob(<?= $row['job_id'] ?>)">
            <i class="fas fa-trash"></i>
        </button>

                                        <!-- Assign TM Button -->
                                        <?php if($is_tracked && $can_assign && ($has_orange_assigned || !$has_any_completed)): ?>
                                               <button class="btn btn-sm btn-warning py-0 px-2 shadow-sm" title="Assign to TMs"
                                                onclick="openAssignModal(this)"
                                                data-job-id="<?= $row['job_id'] ?>"
                                                data-img="<?= h($row['Image_user'] ?? '') ?>"
                                                data-tbl="<?= h($row['Table_user'] ?? '') ?>"
                                                data-wp="<?= h($row['WPContent_user'] ?? '') ?>"
                                                data-nlm="<?= h($row['nlm_user'] ?? '') ?>"
                                                data-jats="<?= h($row['jats_user'] ?? '') ?>"
                                                data-clockss="<?= h($row['clockss_user'] ?? '') ?>"
                                                data-scielo="<?= h($row['Scielo_user'] ?? '') ?>"
                                                data-ebsco="<?= h($row['ebsco_user'] ?? '') ?>"
                                                data-pubmed="<?= h($row['pubmed_user'] ?? '') ?>"
                                                data-biblat="<?= h($row['biblat_user'] ?? '') ?>"
                                                data-doaj="<?= h($row['doaj_user'] ?? '') ?>"
                                                data-html="<?= h($row['HTML_user'] ?? '') ?>"
                                                data-nhtml="<?= h($row['new_html_user'] ?? '') ?>"
                                                data-dhtml="<?= h($row['direct_html_user'] ?? '') ?>"
                                                data-qc="<?= h($row['QC_user'] ?? '') ?>"
                                                
                                                data-val-img="<?= h($row['instruction_to_image'] ?? '') ?>"
                                                data-val-nlm="<?= h($row['nlm'] ?? '') ?>"
                                                data-val-jats="<?= h($row['jats'] ?? '') ?>"
                                                data-val-clockss="<?= h($row['clockss'] ?? '') ?>"
                                                data-val-scielo="<?= h($row['scielo'] ?? '') ?>"
                                                data-val-ebsco="<?= h($row['ebsco'] ?? '') ?>"
                                                data-val-pubmed="<?= h($row['pubmed'] ?? '') ?>"
                                                data-val-biblat="<?= h($row['biblat'] ?? '') ?>"
                                                data-val-doaj="<?= h($row['doaj'] ?? '') ?>"
                                                data-val-html="<?= h($row['html'] ?? '') ?>"
                                                data-val-nhtml="<?= h($row['new_html'] ?? '') ?>"
                                                data-val-dhtml="<?= h($row['direct_html'] ?? '') ?>"
                                                >
                                                <i class="fas fa-user-tag"></i>
                                            </button>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                            <?php endwhile; else: ?>
                            <tr>
                                <td colspan="30" class="text-center py-4 text-muted">
                                    <i class="fas fa-inbox fa-2x mb-2"></i>
                                    <h6>No XML jobs found</h6>
                                    <p class="mb-0">Try adjusting your filters or add a new job.</p>
                                </td>
                            </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <!-- EXCEL ALIGNED ADD/EDIT MODAL -->
    <div class="modal fade" id="jobModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <form method="post" id="jobForm">
                    <input type="hidden" name="action" value="save_job">
                    <input type="hidden" name="job_id" id="modalJobId">
                    
                    <!-- <div class="modal-header text-white py-2" style="background-color: #2c3e50;"> -->
                        <div class="modal-header text-white py-2" style="background-color: #5e6b77;">
                        <h5 class="modal-title" id="jobModalTitle" style="font-size: 16px;"><i class="fas fa-table me-2"></i>Job Details (Excel Format)</h5>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                    </div>
                    
                    <div class="modal-body py-3 bg-light">
                        <!-- Row 1 -->
                        <div class="row g-2 mb-3">
                            <div class="col-md-3">
                                <label class="form-label ">Rec. Date & Time *</label>
                                <input type="datetime-local" name="rec_date" id="rec_date" class="form-control form-control-sm" onchange="updateTAT()" required>
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">Client Name *</label>
                                <select name="client_id" id="modalClientId" class="form-select form-select-sm" required>
                                    <option value="">Select Client...</option>
                                    <?php 
                                    $displayed_clients = [];
                                    foreach($clients as $c): 
                                        $display_name = !empty($c['team_client_name']) ? $c['team_client_name'] : $c['client_name'];
                                        
                                        // Duplicate aagama 1 time mattum print panna check pandrom
                                        if (!in_array($display_name, $displayed_clients)):
                                            $displayed_clients[] = $display_name;
                                    ?>
                                        <option value="<?= $c['client_id'] ?>"><?= h($display_name) ?></option>
                                    <?php 
                                        endif;
                                    endforeach; 
                                    ?>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Journal / Issue</label>
                                <input type="text" name="journal" id="modalJournal" class="form-control form-control-sm">
                            </div>
                        </div>


                            <!-- Row 2 -->
                        <div class="row g-2 mb-3">
                            <div class="col-md-3">
                                <label class="form-label">No. Articles</label>
                                <input type="text" name="no_articles" id="modalNoArticles" class="form-control form-control-sm">
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">NLM</label>
                                <input type="text" name="nlm" id="modalNlm" class="form-control form-control-sm">
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">JATS</label>
                                <input type="text" name="jats" id="modalJats" class="form-control form-control-sm">
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">Clockss</label>
                                <input type="text" name="clockss" id="modalClockss" class="form-control form-control-sm">
                            </div>
                        </div>

                        <!-- Row 2.1 -->
                        <div class="row g-2 mb-3">
                            <div class="col-md-3">
                                <label class="form-label">Scielo</label>
                                <input type="text" name="scielo" id="modalScielo" class="form-control form-control-sm">
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">EBSCO</label>
                                <input type="text" name="ebsco" id="modalEbsco" class="form-control form-control-sm">
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">PubMed</label>
                                <input type="text" name="pubmed" id="modalPubmed" class="form-control form-control-sm">
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">Biblat</label>
                                <input type="text" name="biblat" id="modalBiblat" class="form-control form-control-sm">
                            </div>
                        </div>

                        <!-- Row 2.2 -->
                        <div class="row g-2 mb-3">
                            <div class="col-md-3">
                                <label class="form-label">DOAJ</label>
                                <input type="text" name="doaj" id="modalDoaj" class="form-control form-control-sm">
                            </div>
                        </div>

                        <!-- Row 3 -->
                        <div class="row g-2 mb-3">
                            <div class="col-md-3">
                                <label class="form-label">HTML</label>
                                <input type="text" name="html" id="modalHtml" class="form-control form-control-sm">
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">New HTML</label>
                                <input type="text" name="new_html" id="modalNewHtml" class="form-control form-control-sm">
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">Direct HTML</label>
                                <input type="text" name="direct_html" id="modalDirectHtml" class="form-control form-control-sm">
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">TAT to Client</label>
                                <input type="date" name="tat_to_client" id="modalTat" class="form-control form-control-sm">
                            </div>
                        </div>

                        <!-- Row 4 -->
                        <div class="row g-2 mb-3">
                            <div class="col-md-3">
                                <label class="form-label">Missed TAT</label>
                                <input type="text" name="missed_tat" id="modalMissedTat" class="form-control form-control-sm">
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">Job Status</label>
                                <select name="job_status" id="modalJobStatus" class="form-select form-select-sm">
                                    <option value="">Select...</option>
                                    <option value="Query">Query</option>
                                    <option value="Rework/Correction">Rework/Correction</option>
                                    <option value="Hold">Hold</option>
                                    <option value="Inprocess">Inprocess</option>
                                    <option value="Despatched">Despatched</option>
                                    <option value="PNR">PNR</option>
                                </select>
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">Despatched Date</label>
                                <input type="datetime-local" name="despatched_date" id="modalDespatchedDate" class="form-control form-control-sm">
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">Inst. to Image</label>
                                <select name="inst_to_image" id="modalInstruction" class="form-select form-select-sm">
                                    <option value="">Select...</option>
                                    <option value="Both">Both</option>
                                    <option value="Figure & Inline">Figure & Inline</option>
                                    <option value="Figures Only">Figures Only</option>
                                    <option value="No Images">No Images</option>
                                </select>
                            </div>
                        </div>
                        
                        <!-- Extra Row for Remarks -->
                        <div class="row g-2">
                            <div class="col-12">
                                <label class="form-label">Remarks</label>
                                <input type="text" name="remarks" id="modalRemarks" class="form-control form-control-sm" placeholder="Any tracking remarks...">
                            </div>
                        </div>
                    </div>
                    
                    <div class="modal-footer py-2">
                        <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary btn-sm"><i class="fas fa-save me-1"></i>Save Record</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- TEAM MEMBER ASSIGNMENT MODAL -->
    <?php if($can_assign): ?>
    <div class="modal fade" id="assignModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <form method="post" id="assignForm">
                    <input type="hidden" name="action" value="assign_job">
                    <input type="hidden" name="job_id" id="assignJobId">
                    
                    <div class="modal-header text-white py-2" style="background-color: #5e6b77;">
                        <h5 class="modal-title" style="font-size: 16px;"><i class="fas fa-user-tag me-2"></i>Assign Tasks to Team Members</h5>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                    </div>
                    
                    <div class="modal-body py-3 bg-light">
                        <p class="text-muted small mb-3"><i class="fas fa-info-circle"></i> Select the responsible team member for each sub-task. Dropdowns are disabled automatically if the task is not applicable to this job. <strong>(Assigning tasks will set the Job Status to 'Inprocess')</strong></p>
                        
                        <?php 
                        // Helper to generate Dropdown quickly 
                        function renderUserDropdown($name, $id, $label, $tm_users) {
                            $html = '<div class="col-md-4 mb-3">';
                            $html .= '<label class="form-label fw-bold">'.h($label).'</label>';
                            $html .= '<select name="'.h($name).'" id="'.h($id).'" class="form-select form-select-sm">';
                            $html .= '<option value="">-- Unassigned --</option>';
                            foreach($tm_users as $tm) {
                                // Extract Value and Display format
                                $val = is_array($tm) ? $tm['username'] : $tm;
                                $display = is_array($tm) ? $tm['name'] . ' (' . $tm['username'] . ')' : $tm;
                                $html .= '<option value="'.h($val).'">'.h($display).'</option>';
                            }
                            $html .= '<option value="N/A">N/A</option>';
                            $html .= '</select></div>';
                            return $html;
                        }
                        ?>
                        
                        <div class="row g-2">
                            <?= renderUserDropdown('nlm_user', 'assign_nlm', 'NLM User', $tm_users) ?>
                            <?= renderUserDropdown('jats_user', 'assign_jats', 'JATS User', $tm_users) ?>
                            <?= renderUserDropdown('clockss_user', 'assign_clockss', 'Clockss User', $tm_users) ?>

                            <?= renderUserDropdown('scielo_user', 'assign_scielo', 'Scielo User', $tm_users) ?>
                            <?= renderUserDropdown('ebsco_user', 'assign_ebsco', 'EBSCO User', $tm_users) ?>
                            <?= renderUserDropdown('pubmed_user', 'assign_pubmed', 'PubMed User', $tm_users) ?>
                            
                            <?= renderUserDropdown('biblat_user', 'assign_biblat', 'Biblat User', $tm_users) ?>
                            <?= renderUserDropdown('doaj_user', 'assign_doaj', 'DOAJ User', $tm_users) ?>
                            <?= renderUserDropdown('qc_user', 'assign_qc', 'QC User', $tm_users) ?>
                            
                            <?= renderUserDropdown('html_user', 'assign_html', 'HTML User', $tm_users) ?>
                            <?= renderUserDropdown('new_html_user', 'assign_nhtml', 'New HTML User', $tm_users) ?>
                            <?= renderUserDropdown('direct_html_user', 'assign_dhtml', 'Direct HTML User', $tm_users) ?>
                            <?= renderUserDropdown('image_user', 'assign_img', 'Image User', $tm_users) ?>
                            
                            <?= renderUserDropdown('table_user', 'assign_tbl', 'Table User', $tm_users) ?>
                            <?= renderUserDropdown('wp_user', 'assign_wp', 'WPContent User', $tm_users) ?>
                        </div>
                    </div>
                    
                    <div class="modal-footer py-2">
                        <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary btn-sm fw-bold"><i class="fas fa-check-circle me-1"></i>Save Assignments</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
    // Enable all selects automatically upon submitting so disabled inputs accurately submit 'N/A' to DB
    document.addEventListener("DOMContentLoaded", function() {
        const assignForm = document.getElementById('assignForm');
        if(assignForm) {
            assignForm.addEventListener('submit', function() {
                const selects = this.querySelectorAll('select');
                selects.forEach(s => s.disabled = false);
            });
        }
    });

    // function updateTAT() {
    //     let recInput = document.getElementById('rec_date').value;
    //     if (recInput) {
    //         let d = new Date(recInput);
    //         let hour = d.getHours();
    //         let addDays = hour >= 17 ? 6 : 5;
            
    //         let tatD = new Date(d);
    //         tatD.setDate(tatD.getDate() + addDays);
            
    //         let formattedDate = tatD.getFullYear() + "-" + 
    //                             String(tatD.getMonth() + 1).padStart(2, '0') + "-" + 
    //                             String(tatD.getDate()).padStart(2, '0');
    //         document.getElementById('modalTat').value = formattedDate;
    //     }
    // }
    function updateTAT() {
    let recInput = document.getElementById('rec_date').value; 
    let articleCount = parseInt(document.getElementById('modalNoArticles').value) || 0; 
    let tatInput = document.getElementById('modalTat'); 

    if (recInput) {
        let d = new Date(recInput);
        let daysToAdd = 2; // Default

        // Set days to add based on article count
        if (articleCount > 0 && articleCount <= 100) {
            daysToAdd = 3; 
        } else if (articleCount > 100) {
            daysToAdd = 5; 
        }
        
        let tatD = new Date(d);
        let addedCount = 0;

        // Loop to add working days (Excluding Sundays)
        while (addedCount < daysToAdd) {
            tatD.setDate(tatD.getDate() + 1); // Move to next day
            
            // 0 is Sunday. If it's NOT Sunday, count it as a day added.
            if (tatD.getDay() !== 0) {
                addedCount++;
            }
        }
        
        // Format the date for the input field (YYYY-MM-DD)
        let y = tatD.getFullYear();
        let m = String(tatD.getMonth() + 1).padStart(2, '0');
        let day = String(tatD.getDate()).padStart(2, '0');
        
        tatInput.value = `${y}-${m}-${day}`;
    }
}
// Add these listeners at the end of $(document).ready
document.getElementById('modalNoArticles').addEventListener('input', updateTAT);
document.getElementById('rec_date').addEventListener('change', updateTAT);

    function openJobModal(btn = null) {
        document.getElementById('jobForm').reset();
        
        if (btn) {
            // Edit Mode
            document.getElementById('jobModalTitle').innerHTML = '<i class="fas fa-edit me-2"></i>Edit Record (Excel Format)';
            document.getElementById('modalJobId').value = btn.dataset.jobId;
            document.getElementById('modalClientId').value = btn.dataset.clientId;
            
            document.getElementById('rec_date').value = btn.dataset.recDate || '';
            document.getElementById('modalJournal').value = btn.dataset.journal || '';
            document.getElementById('modalNoArticles').value = btn.dataset.articles || '';

            document.getElementById('modalNlm').value = btn.dataset.nlm || '';
            document.getElementById('modalJats').value = btn.dataset.jats || '';
            document.getElementById('modalClockss').value = btn.dataset.clockss || '';
            document.getElementById('modalScielo').value = btn.dataset.scielo || '';
            document.getElementById('modalEbsco').value = btn.dataset.ebsco || '';
            document.getElementById('modalPubmed').value = btn.dataset.pubmed || '';
            document.getElementById('modalBiblat').value = btn.dataset.biblat || '';
            document.getElementById('modalDoaj').value = btn.dataset.doaj || '';
            document.getElementById('modalHtml').value = btn.dataset.html || '';
            document.getElementById('modalNewHtml').value = btn.dataset.newHtml || '';
            document.getElementById('modalDirectHtml').value = btn.dataset.directHtml || '';
            
            document.getElementById('modalTat').value = btn.dataset.tat || '';
            document.getElementById('modalMissedTat').value = btn.dataset.missedTat || '';
            document.getElementById('modalJobStatus').value = btn.dataset.jobStatus || '';
            document.getElementById('modalDespatchedDate').value = btn.dataset.despatchedDate || '';
            document.getElementById('modalRemarks').value = btn.dataset.remarks || '';

            // Handle Instruction Select - Safely match case variations like "both" vs "Both"
            let instVal = (btn.dataset.instruction || '').trim().toLowerCase();
            let selectInst = document.getElementById('modalInstruction');
            selectInst.value = ''; // default empty
            Array.from(selectInst.options).forEach(opt => {
                if(opt.value.toLowerCase() === instVal) {
                    selectInst.value = opt.value;
                }
            });

        } else {
            // Add Mode
            document.getElementById('jobModalTitle').innerHTML = '<i class="fas fa-plus-circle me-2"></i>Add New Record (Excel Format)';
            document.getElementById('modalJobId').value = '';
            document.getElementById('modalJobStatus').value = '';
            document.getElementById('modalInstruction').value = '';
            document.getElementById('modalDespatchedDate').value = '';
            
            let now = new Date();
            let offset = now.getTimezoneOffset() * 60000;
            let localISOTime = (new Date(now - offset)).toISOString().slice(0,16);
            
            document.getElementById('rec_date').value = localISOTime;
            
            updateTAT();
        }
        
        const modal = new bootstrap.Modal(document.getElementById('jobModal'));
        modal.show();
    }

    // Logic to populate and conditionally enable fields in the Assignment Modal
    function openAssignModal(btn) {
        document.getElementById('assignJobId').value = btn.dataset.jobId;
        
        // Checks if there's a valid data entry showing the task needs assignment
        const isValValid = (val) => {
            if (val === undefined || val === null) return false;
            const v = val.trim().toLowerCase();
            return v !== '' && v !== '-' && v !== 'n/a' && v !== 'na';
        };

        // Safely set values and disable them if there's no dependent valid count/status
        const setSelectVal = (id, assignedUser, taskValue) => {
            const el = document.getElementById(id);
            if (el) {
                // Remove raw dash UI for initial selection process
                el.value = (assignedUser === '-') ? '' : assignedUser;
                
                // Toggle disabled attribute dynamically based on provided column status validation
                if (taskValue !== undefined) {
                    if (isValValid(taskValue)) {
                        el.disabled = false;
                    } else {
                        // Mark it as automatically unneeded (N/A)
                        el.value = 'N/A';
                        el.disabled = true;
                    }
                } else {
                    // Task without an explicit row metric, leave enabled manually
                    el.disabled = false; 
                }
            }
        };

        // Bind assigned user data alongside the database "value" dependencies
        setSelectVal('assign_img', btn.dataset.img, btn.dataset.valImg);
        setSelectVal('assign_tbl', btn.dataset.tbl);
        setSelectVal('assign_wp', btn.dataset.wp);
        setSelectVal('assign_nlm', btn.dataset.nlm, btn.dataset.valNlm);
        setSelectVal('assign_jats', btn.dataset.jats, btn.dataset.valJats);
        setSelectVal('assign_clockss', btn.dataset.clockss, btn.dataset.valClockss);
        
        setSelectVal('assign_scielo', btn.dataset.scielo, btn.dataset.valScielo);
        setSelectVal('assign_ebsco', btn.dataset.ebsco, btn.dataset.valEbsco);
        setSelectVal('assign_pubmed', btn.dataset.pubmed, btn.dataset.valPubmed);
        
        setSelectVal('assign_biblat', btn.dataset.biblat, btn.dataset.valBiblat);
        setSelectVal('assign_doaj', btn.dataset.doaj, btn.dataset.valDoaj);

        setSelectVal('assign_html', btn.dataset.html, btn.dataset.valHtml);
        setSelectVal('assign_nhtml', btn.dataset.nhtml, btn.dataset.valNhtml);
        setSelectVal('assign_dhtml', btn.dataset.dhtml, btn.dataset.valDhtml);
        setSelectVal('assign_qc', btn.dataset.qc);

        const modal = new bootstrap.Modal(document.getElementById('assignModal'));
        modal.show();
    }
    // --- INSERT THE DELETE FUNCTION HERE ---
    function deleteJob(jobId) {
        if (confirm("Are you sure you want to delete this record? This action cannot be undone.")) {
            const form = document.createElement('form');
            form.method = 'POST';
            form.innerHTML = `
                <input type="hidden" name="action" value="delete_job">
                <input type="hidden" name="job_id" value="${jobId}">
            `;
            document.body.appendChild(form);
            form.submit();
        }
    }
    </script>
</body>
</html>