<?php
// PHP Configuration: Must be the absolute first line
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Increase upload limits
ini_set('upload_max_filesize', '10M');
ini_set('post_max_size', '10M');
ini_set('max_execution_time', '300');

require 'db.php';

if (session_status() === PHP_SESSION_NONE) { 
    session_start(); 
}

// Helper functions
function h($s) { 
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); 
}

function formatDate($d) {
    if (empty($d) || $d === '0000-00-00' || $d === '0000-00-00 00:00:00') return '—';
    return date('d-m-Y', strtotime($d));
}

// Bulletproof redirect
function redirect_with($params = []) {
    $base = 'pubcode.php';
    if (!empty($params)) $base .= '?' . http_build_query($params);
    if (!headers_sent()) {
        header("Location: $base");
    } else {
        echo "<script>window.location.href='$base';</script>";
    }
    exit;
}

// ==========================================
// HANDLE EXCEL SAMPLE DOWNLOAD
// ==========================================
if (isset($_GET['action']) && $_GET['action'] === 'download_sample') {
    header("Content-Type: application/vnd.ms-excel; charset=utf-8");
    header("Content-Disposition: attachment; filename=\"Sample_Journal_Import.xls\"");
    header("Pragma: no-cache");
    header("Expires: 0");

    echo '<html xmlns:x="urn:schemas-microsoft-com:office:excel">';
    echo '<head><meta http-equiv="content-type" content="text/plain; charset=UTF-8"/></head>';
    echo '<body>';
    echo '<table border="1" style="font-family: Calibri, sans-serif; font-size: 11pt;">';
    echo '<tr style="background-color: #bcbcbc; font-weight: bold; text-align: center;">';
    
    // Exactly matches the DB and Grid sequence
    $headers = [
        'Journal Name', 'Journal Ref', 'PubCode', 'ShortCode', 'Frequency', 
        'Issues till 2023', 'Point of revenue', 'Specialty', 'Origin', 
        'Avg pages/Issue', 'Avg articles/Issue', 'Performance', 'Avg Article inflow/Month', 
        'Template', 'New TL', 'New PE', 'Manager', 'E-mail ID', 'PE Contact', 
        'Status', 'Live on eJP', 'URL'
    ];
    
    foreach ($headers as $h) echo "<th>$h</th>";
    echo '</tr>';
    
    // Sample Data Row
    echo '<tr style="text-align: center;">';
    echo '<td>Sample Journal of Science</td><td>SJS01</td><td>SJS</td><td>sci</td><td>Monthly</td>';
    echo '<td>12</td><td>Print</td><td>Science</td><td>International</td>';
    echo '<td>60</td><td>10</td><td>Ontime</td><td>5</td>';
    echo '<td>Template 1</td><td>John Doe</td><td>Jane Smith</td><td>Manager A</td><td>email@test.com</td><td>1234567890</td>';
    echo '<td>Active</td><td>Yes</td><td>http://example.com</td>';
    echo '</tr>';
    
    echo '</table></body></html>';
    exit;
}

// Fetch Options
function getFrequencyOptions() { return ['Monthly', 'Bi-Monthly', 'Quarterly', 'Semi-Annual', 'Annual', 'Weekly', 'Daily', 'Irregular']; }
function getOriginOptions() { return ['National', 'International', 'Regional', 'Local']; }
function getPerformanceOptions() { return ['Delayed', 'Ontime']; }
function getStatusOptions() { return ['Active', 'Onhold']; }

function getSpecialtyOptions($mysqli) {
    $specialties = [];
    $sql = "SELECT specialty_id, specialty_name FROM journal_specialty_master WHERE status = 'Active' ORDER BY specialty_name";
    $result = $mysqli->query($sql);
    if ($result) while ($row = $result->fetch_assoc()) $specialties[] = $row;
    return $specialties;
}

function getManagers($mysqli) {
    $managers = [];
    $sql = "SELECT team_id, team_name FROM team_master WHERE status = 'Active' ORDER BY team_name";
    $result = $mysqli->query($sql);
    if ($result) while ($row = $result->fetch_assoc()) $managers[] = $row;
    return $managers;
}

function getTemplateOptions($mysqli) {
    $templates = [];
    $sql = "SELECT template_id, template_name FROM template_master WHERE status = 'Active' ORDER BY template_name";
    $result = $mysqli->query($sql);
    if ($result) while ($row = $result->fetch_assoc()) $templates[] = $row;
    return $templates;
}

// ==========================================
// HYBRID EXCEL/CSV FILE PARSER
// ==========================================
function parseUploadedFile($file_path, $file_name) {
    $ext = strtolower(pathinfo($file_name, PATHINFO_EXTENSION));
    $dataRows = [];

    if ($ext === 'csv') {
        if (($handle = fopen($file_path, "r")) !== FALSE) {
            while (($data = fgetcsv($handle, 10000, ",")) !== FALSE) {
                $dataRows[] = $data;
            }
            fclose($handle);
        }
    } elseif ($ext === 'xls') {
        // Parse the HTML-based .xls format we generated in the sample download
        $html = file_get_contents($file_path);
        libxml_use_internal_errors(true);
        $dom = new DOMDocument();
        $dom->loadHTML($html);
        libxml_clear_errors();

        $rows = $dom->getElementsByTagName('tr');
        foreach ($rows as $row) {
            $rowData = [];
            $cells = $row->getElementsByTagName('td');
            if ($cells->length == 0) $cells = $row->getElementsByTagName('th');
            foreach ($cells as $cell) {
                $rowData[] = trim($cell->nodeValue);
            }
            if (!empty($rowData)) $dataRows[] = $rowData;
        }
    }
    return $dataRows;
}

function processImportData($file_path, $file_name, $mysqli) {
    $imported_count = 0; $updated_count = 0; $skipped_count = 0; $error_details = [];
    
    $dataRows = parseUploadedFile($file_path, $file_name);
    if (empty($dataRows)) return ['imported'=>0, 'updated'=>0, 'skipped'=>0, 'errors'=>['Could not read file format.']];

    // Pre-fetch relational ID mappings
    $spec_map = [];
    $res = $mysqli->query("SELECT specialty_id, specialty_name FROM journal_specialty_master");
    if ($res) while ($row = $res->fetch_assoc()) $spec_map[strtolower(trim($row['specialty_name']))] = $row['specialty_id'];

    $team_map = [];
    $res = $mysqli->query("SELECT team_id, team_name FROM team_master");
    if ($res) while ($row = $res->fetch_assoc()) $team_map[strtolower(trim($row['team_name']))] = $row['team_id'];

    $tpl_map = [];
    $res = $mysqli->query("SELECT template_id, template_name FROM template_master");
    if ($res) while ($row = $res->fetch_assoc()) $tpl_map[strtolower(trim($row['template_name']))] = $row['template_id'];
    
    foreach ($dataRows as $index => $data) {
        if ($index === 0) continue; // Skip header row
        
        if (count($data) < 22) {
            $skipped_count++;
            $error_details[] = "Row ".($index+1).": Insufficient columns.";
            continue;
        }
        
        // Exact DB Sequence mapping
        $journal_name = trim($data[0] ?? '');
        $journal_ref = trim($data[1] ?? '');
        $pubcode = trim($data[2] ?? '');
        $shortcode = trim($data[3] ?? '');
        $frequency = trim($data[4] ?? '');
        $issues_till_2023 = (int)trim($data[5] ?? '0');
        $point_of_revenue = trim($data[6] ?? '');
        
        $specialty_raw = trim($data[7] ?? '');
        $specialty_id = !empty($specialty_raw) ? ($spec_map[strtolower($specialty_raw)] ?? 0) : 0;
        
        $origin = trim($data[8] ?? '');
        $avg_pages_per_issue = (int)trim($data[9] ?? '0');
        $avg_articles_per_issue = (int)trim($data[10] ?? '0');
        
        $perf_raw = trim($data[11] ?? '');
        if (strtolower($perf_raw) === 'on time') $perf_raw = 'Ontime';
        $performance = $perf_raw;
        
        $avg_article_inflow_month = trim($data[12] ?? '');
        
        $template_raw = trim($data[13] ?? '');
        $template_id = !empty($template_raw) ? ($tpl_map[strtolower($template_raw)] ?? 0) : 0;
        
        $new_tl = trim($data[14] ?? '');
        $new_pe = trim($data[15] ?? '');
        
        $manager_raw = trim($data[16] ?? '');
        $manager_id = !empty($manager_raw) ? ($team_map[strtolower($manager_raw)] ?? 0) : 0;
        
        $email_id = trim($data[17] ?? '');
        $pe_contact_number = trim($data[18] ?? '');
        
        $status_raw = trim($data[19] ?? 'Active');
        if (empty($status_raw)) $status_raw = 'Active';
        if (strtolower(str_replace(' ', '', $status_raw)) === 'onhold') $status_raw = 'Onhold';
        $status = $status_raw;
        
        $live_on_ejp_raw = strtolower(trim($data[20] ?? ''));
        $live_on_ejp = ($live_on_ejp_raw === 'yes' || $live_on_ejp_raw === '1' || $live_on_ejp_raw === 'true') ? 1 : 0;
        
        $url = trim($data[21] ?? '');

        if (empty($journal_name)) {
            $skipped_count++;
            $error_details[] = "Row ".($index+1).": Missing Journal Name";
            continue;
        }

        // Check existence
        $checkStmt = $mysqli->prepare("SELECT journal_id FROM pubcode WHERE journal_name = ?");
        $checkStmt->bind_param("s", $journal_name);
        $checkStmt->execute();
        $checkRes = $checkStmt->get_result();
        $exists = $checkRes->num_rows > 0;
        $row_id = $exists ? $checkRes->fetch_assoc()['journal_id'] : 0;
        $checkStmt->close();

        if (!$exists) {
            $insertStmt = $mysqli->prepare("INSERT INTO pubcode (journal_name, journal_ref, pubcode, shortcode, frequency, issues_till_2023, point_of_revenue, specialty_id, origin, avg_pages_per_issue, avg_articles_per_issue, performance, avg_article_inflow_month, template_id, new_tl, new_pe, manager_id, email_id, pe_contact_number, status, live_on_ejp, url, created_date) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())");
            $insertStmt->bind_param("sssssisiisiissississis", $journal_name, $journal_ref, $pubcode, $shortcode, $frequency, $issues_till_2023, $point_of_revenue, $specialty_id, $origin, $avg_pages_per_issue, $avg_articles_per_issue, $performance, $avg_article_inflow_month, $template_id, $new_tl, $new_pe, $manager_id, $email_id, $pe_contact_number, $status, $live_on_ejp, $url);
            
            if ($insertStmt->execute()) $imported_count++;
            else { $skipped_count++; $error_details[] = "Row ".($index+1).": " . $mysqli->error; }
            $insertStmt->close();
        } else {
            $updateStmt = $mysqli->prepare("UPDATE pubcode SET journal_ref=?, pubcode=?, shortcode=?, frequency=?, issues_till_2023=?, point_of_revenue=?, specialty_id=?, origin=?, avg_pages_per_issue=?, avg_articles_per_issue=?, performance=?, avg_article_inflow_month=?, template_id=?, new_tl=?, new_pe=?, manager_id=?, email_id=?, pe_contact_number=?, status=?, live_on_ejp=?, url=?, updated_date=NOW() WHERE journal_id=?");
            $updateStmt->bind_param("ssssisiisiissississisi", $journal_ref, $pubcode, $shortcode, $frequency, $issues_till_2023, $point_of_revenue, $specialty_id, $origin, $avg_pages_per_issue, $avg_articles_per_issue, $performance, $avg_article_inflow_month, $template_id, $new_tl, $new_pe, $manager_id, $email_id, $pe_contact_number, $status, $live_on_ejp, $url, $row_id);
            
            if ($updateStmt->execute()) $updated_count++;
            $updateStmt->close();
        }
    }
    
    return ['imported' => $imported_count, 'updated' => $updated_count, 'skipped' => $skipped_count, 'errors' => $error_details];
}

// ==========================================
// HANDLE POST ACTIONS
// ==========================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'import_excel') {
        if (!isset($_FILES['excel_file']) || $_FILES['excel_file']['error'] !== 0) {
            redirect_with(['msg' => 'File upload error. Please select a valid file.', 'type' => 'danger']);
        }
        
        $file_ext = strtolower(pathinfo($_FILES['excel_file']['name'], PATHINFO_EXTENSION));
        if (!in_array($file_ext, ['csv', 'xls'])) {
            redirect_with(['msg' => 'Invalid file format. Please upload .csv or .xls files only.', 'type' => 'warning']);
        }
        
        $result = processImportData($_FILES['excel_file']['tmp_name'], $_FILES['excel_file']['name'], $mysqli);
        
        $msg = "Process Complete.<br><strong>Imported:</strong> {$result['imported']} new journals.<br><strong>Updated:</strong> {$result['updated']} existing journals.<br><strong>Skipped:</strong> {$result['skipped']} rows.";
        if (!empty($result['errors'])) $msg .= "<br><small class='text-danger'>" . implode("<br>", array_slice($result['errors'], 0, 5)) . "</small>";
        
        $type = ($result['imported'] > 0 || $result['updated'] > 0) ? 'success' : 'warning';
        redirect_with(['msg' => $msg, 'type' => $type]);
    }
    
    if ($action === 'add' || $action === 'edit') {
        $journal_name = trim($_POST['journal_name'] ?? '');
        $journal_ref = trim($_POST['journal_ref'] ?? '');
        $pubcode = trim($_POST['pubcode'] ?? '');
        $shortcode = trim($_POST['shortcode'] ?? '');
        $frequency = trim($_POST['frequency'] ?? '');
        $issues_till_2023 = (int)trim($_POST['issues_till_2023'] ?? '');
        $point_of_revenue = trim($_POST['point_of_revenue'] ?? '');
        $specialty_id = (int)trim($_POST['specialty_id'] ?? '');
        $origin = trim($_POST['origin'] ?? '');
        $avg_pages_per_issue = (int)trim($_POST['avg_pages_per_issue'] ?? '');
        $avg_articles_per_issue = (int)trim($_POST['avg_articles_per_issue'] ?? '');
        $performance = trim($_POST['performance'] ?? '');
        $avg_article_inflow_month = trim($_POST['avg_article_inflow_month'] ?? '');
        $template_id = (int)trim($_POST['template_id'] ?? '');
        $new_tl = trim($_POST['new_tl'] ?? '');
        $new_pe = trim($_POST['new_pe'] ?? '');
        $manager_id = (int)trim($_POST['manager_id'] ?? '');
        $email_id = trim($_POST['email_id'] ?? '');
        $pe_contact_number = trim($_POST['pe_contact_number'] ?? '');
        $status = trim($_POST['status'] ?? 'Active');
        $live_on_ejp = isset($_POST['live_on_ejp']) ? 1 : 0;
        $url = trim($_POST['url'] ?? '');

        if (empty($journal_name)) {
            redirect_with(['msg' => 'Journal Name is required.', 'type' => 'danger']);
        }

        if ($action === 'add') {
            $check_stmt = $mysqli->prepare("SELECT journal_id FROM pubcode WHERE journal_name = ?");
            $check_stmt->bind_param("s", $journal_name);
            $check_stmt->execute();
            $check_stmt->store_result();
            if ($check_stmt->num_rows > 0) redirect_with(['msg' => 'Journal with this name already exists.', 'type' => 'danger']);
            $check_stmt->close();

            $stmt = $mysqli->prepare("INSERT INTO pubcode (journal_name, journal_ref, pubcode, shortcode, frequency, issues_till_2023, point_of_revenue, specialty_id, origin, avg_pages_per_issue, avg_articles_per_issue, performance, avg_article_inflow_month, template_id, new_tl, new_pe, manager_id, email_id, pe_contact_number, status, live_on_ejp, url, created_date) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())");
            $stmt->bind_param("sssssisiisiissississis", $journal_name, $journal_ref, $pubcode, $shortcode, $frequency, $issues_till_2023, $point_of_revenue, $specialty_id, $origin, $avg_pages_per_issue, $avg_articles_per_issue, $performance, $avg_article_inflow_month, $template_id, $new_tl, $new_pe, $manager_id, $email_id, $pe_contact_number, $status, $live_on_ejp, $url);

            if ($stmt->execute()) redirect_with(['msg' => "Journal added successfully", 'type' => 'success']);
            else redirect_with(['msg' => 'Error adding journal: ' . $stmt->error, 'type' => 'danger']);
        }

        if ($action === 'edit') {
            $journal_id = (int)($_POST['journal_id'] ?? 0);
            if ($journal_id > 0) {
                $check_stmt = $mysqli->prepare("SELECT journal_id FROM pubcode WHERE journal_name = ? AND journal_id != ?");
                $check_stmt->bind_param("si", $journal_name, $journal_id);
                $check_stmt->execute();
                $check_stmt->store_result();
                if ($check_stmt->num_rows > 0) redirect_with(['msg' => 'Journal with this name already exists.', 'type' => 'danger']);
                $check_stmt->close();

                $stmt = $mysqli->prepare("UPDATE pubcode SET journal_name = ?, journal_ref = ?, pubcode = ?, shortcode = ?, frequency = ?, issues_till_2023 = ?, point_of_revenue = ?, specialty_id = ?, origin = ?, avg_pages_per_issue = ?, avg_articles_per_issue = ?, performance = ?, avg_article_inflow_month = ?, template_id = ?, new_tl = ?, new_pe = ?, manager_id = ?, email_id = ?, pe_contact_number = ?, status = ?, live_on_ejp = ?, url = ?, updated_date = NOW() WHERE journal_id = ?");
                $stmt->bind_param("sssssisiisiissississisi", $journal_name, $journal_ref, $pubcode, $shortcode, $frequency, $issues_till_2023, $point_of_revenue, $specialty_id, $origin, $avg_pages_per_issue, $avg_articles_per_issue, $performance, $avg_article_inflow_month, $template_id, $new_tl, $new_pe, $manager_id, $email_id, $pe_contact_number, $status, $live_on_ejp, $url, $journal_id);

                if ($stmt->execute()) redirect_with(['msg' => "Journal updated successfully", 'type' => 'success']);
                else redirect_with(['msg' => 'Error updating journal.', 'type' => 'danger']);
            }
        }
    }

    if ($action === 'delete') {
        $journal_id = (int)($_POST['journal_id'] ?? 0);
        if ($journal_id > 0) {
            $del = $mysqli->prepare("DELETE FROM pubcode WHERE journal_id = ?");
            $del->bind_param("i", $journal_id);
            $del->execute();
            redirect_with(['msg' => "Journal deleted", 'type' => 'warning']);
        }
    }

    if ($action === 'toggle_status') {
        $journal_id = (int)($_POST['journal_id'] ?? 0);
        if ($journal_id > 0) {
            $res = $mysqli->query("SELECT status FROM pubcode WHERE journal_id = $journal_id")->fetch_assoc();
            $newStatus = (strtolower($res['status']) === 'active') ? 'Onhold' : 'Active';
            $mysqli->query("UPDATE pubcode SET status = '$newStatus', updated_date = NOW() WHERE journal_id = $journal_id");
            redirect_with(['msg' => "Status changed", 'type' => 'info']);
        }
    }

    if ($action === 'toggle_ejp') {
        $journal_id = (int)($_POST['journal_id'] ?? 0);
        if ($journal_id > 0) {
            $res = $mysqli->query("SELECT live_on_ejp FROM pubcode WHERE journal_id = $journal_id")->fetch_assoc();
            $newEJP = $res['live_on_ejp'] ? 0 : 1;
            $mysqli->query("UPDATE pubcode SET live_on_ejp = $newEJP, updated_date = NOW() WHERE journal_id = $journal_id");
            redirect_with(['msg' => "eJP status changed", 'type' => 'info']);
        }
    }

    if ($action !== 'import_excel') redirect_with();
}

// ==========================================
// GET DATA FOR VIEW/EDIT
// ==========================================
$mode = $_GET['mode'] ?? 'view';
$editJournal = null;

if ($mode === 'edit') {
    $id = (int)($_GET['id'] ?? 0);
    if ($id > 0) {
        $stmt = $mysqli->prepare("SELECT jm.*, tm.team_name as manager_name, jsm.specialty_name, tpl.template_name FROM pubcode jm LEFT JOIN team_master tm ON jm.manager_id = tm.team_id LEFT JOIN journal_specialty_master jsm ON jm.specialty_id = jsm.specialty_id LEFT JOIN template_master tpl ON jm.template_id = tpl.template_id WHERE jm.journal_id = ?");
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $editJournal = $stmt->get_result()->fetch_assoc();
        $stmt->close();
    }
    if (!$editJournal) $mode = 'view';
}

$q = trim($_GET['q'] ?? '');
$fstatus = $_GET['status'] ?? '';
$fspecialty = $_GET['specialty'] ?? '';
$forigin = $_GET['origin'] ?? '';
$sort = $_GET['sort'] ?? 'created_date';
$dir = strtolower($_GET['dir'] ?? 'desc');

$allowed_sorts = ['journal_name', 'pubcode', 'frequency', 'specialty_name', 'origin', 'status', 'live_on_ejp', 'created_date', 'updated_date'];
if (!in_array($sort, $allowed_sorts)) $sort = 'created_date';

$sql = "SELECT jm.*, tm.team_name as manager_name, jsm.specialty_name, tpl.template_name 
        FROM pubcode jm 
        LEFT JOIN team_master tm ON jm.manager_id = tm.team_id
        LEFT JOIN journal_specialty_master jsm ON jm.specialty_id = jsm.specialty_id
        LEFT JOIN template_master tpl ON jm.template_id = tpl.template_id";
$cond = [];
$params = [];
$types = '';

if ($q !== '') {
    $cond[] = "(jm.journal_name LIKE ? OR jm.journal_ref LIKE ? OR jm.pubcode LIKE ? OR jm.shortcode LIKE ? OR jm.email_id LIKE ? OR tm.team_name LIKE ? OR jsm.specialty_name LIKE ? OR tpl.template_name LIKE ?)";
    $params = array_fill(0, 8, "%$q%");
    $types .= str_repeat('s', 8);
}
if ($fstatus !== '') { $cond[] = "jm.status = ?"; $params[] = $fstatus; $types .= 's'; }
if ($fspecialty !== '') { $cond[] = "jm.specialty_id = ?"; $params[] = $fspecialty; $types .= 'i'; }
if ($forigin !== '') { $cond[] = "jm.origin = ?"; $params[] = $forigin; $types .= 's'; }

if (!empty($cond)) $sql .= " WHERE " . implode(" AND ", $cond);
$sql .= " ORDER BY $sort $dir";

if (!empty($types)) {
    $stmt = $mysqli->prepare($sql);
    if ($stmt) { $stmt->bind_param($types, ...$params); $stmt->execute(); $journals = $stmt->get_result(); }
} else {
    $journals = $mysqli->query($sql);
}

$frequency_options = getFrequencyOptions();
$specialty_options = getSpecialtyOptions($mysqli);
$origin_options = getOriginOptions();
$performance_options = getPerformanceOptions();
$status_options = getStatusOptions();
$template_options = getTemplateOptions($mysqli);
$managers = getManagers($mysqli);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Pub Code</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body { background-color: #f8f9fa; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; }
        .main-content { padding: 20px; max-width: 2500px; margin: 0 auto; }
        .table th { background-color:  #5e6b77 !important; color: #ffffff !important; border: none; white-space: nowrap; }
        .table td { vertical-align: middle; font-size: 0.9rem; }
        .card { box-shadow: 0 0.125rem 0.25rem rgba(0, 0, 0, 0.075); border: 1px solid rgba(0, 0, 0, 0.125); }
        .journal-name-cell, .email-cell, .url-cell { max-width: 200px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; cursor: pointer; position: relative; }
        .journal-name-cell:hover::after { content: attr(title); position: absolute; top: 100%; left: 0; background: white; border: 1px solid #ddd; padding: 10px; z-index: 1000; max-width: 400px; box-shadow: 0 0 10px rgba(0,0,0,0.1); white-space: normal; word-wrap: break-word; }
        .badge-small { font-size: 0.75em; padding: 0.25em 0.5em; }
        .stats-cell { text-align: center; font-weight: bold; }
        .form-section { background: #f8f9fa; padding: 15px; border-radius: 5px; margin-bottom: 20px; border-left: 4px solid #007bff; }
        .form-section h6 { color: #007bff; margin-bottom: 15px; }
        .modal-overlay { display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 9999; }
        .modal-box { background: white; width: 90%; max-width: 800px; margin: 50px auto; border-radius: 8px; box-shadow: 0 5px 15px rgba(0,0,0,0.3); }
        .modal-header { background: #ffc107; padding: 15px 20px; border-radius: 8px 8px 0 0; display: flex; justify-content: space-between; align-items: center; }
        .modal-header h5 { margin: 0; color: #000; font-weight: bold; }
        .modal-close { font-size: 24px; cursor: pointer; font-weight: bold; }
        .modal-close:hover { color: #666; }
        .modal-body { padding: 20px; }
        .modal-footer { padding: 15px 20px; background: #f8f9fa; border-top: 1px solid #dee2e6; border-radius: 0 0 8px 8px; text-align: right; }
    </style>
</head>
<body>
    <?php require 'header.php'; ?>

    <div class="main-content">
        <?php if (!empty($_GET['msg'])): ?>
        <div class="alert alert-<?= h($_GET['type'] ?? 'info') ?> alert-dismissible fade show" role="alert">
            <?= $_GET['msg'] ?>
            <button type="button" class="btn-close" onclick="this.parentElement.style.display='none'"></button>
        </div>
        <?php endif; ?>

        <?php if ($mode === 'add' || $mode === 'edit'): ?>
            <div class="d-flex justify-content-between align-items-center mb-4">
                <h4><i class="fas fa-book me-2"></i><?= $mode === 'edit' ? 'Edit Journal' : 'Add New Journal' ?></h4>
                <a href="pubcode.php" class="btn btn-secondary btn-sm"><i class="fas fa-arrow-left me-1"></i> Back to List</a>
            </div>
            
            <div class="card"><div class="card-body">
                <form method="post" autocomplete="off">
                        <input type="hidden" name="action" value="<?= $mode ?>">
                        <?php if ($mode === 'edit'): ?><input type="hidden" name="journal_id" value="<?= (int)($editJournal['journal_id'] ?? 0) ?>"><?php endif; ?>

                        <div class="form-section">
                            <h6><i class="fas fa-info-circle me-2"></i>Basic Information</h6>
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">Journal Name <span class="text-danger">*</span></label>
                                    <input type="text" class="form-control" name="journal_name" value="<?= isset($editJournal['journal_name']) ? h($editJournal['journal_name']) : '' ?>" required>
                                </div>
                                <div class="col-md-3 mb-3">
                                    <label class="form-label">Journal Ref</label>
                                    <input type="text" class="form-control" name="journal_ref" value="<?= isset($editJournal['journal_ref']) ? h($editJournal['journal_ref']) : '' ?>">
                                </div>
                                <div class="col-md-3 mb-3">
                                    <label class="form-label">PubCode</label>
                                    <input type="text" class="form-control" name="pubcode" value="<?= isset($editJournal['pubcode']) ? h($editJournal['pubcode']) : '' ?>">
                                </div>
                                <div class="col-md-3 mb-3">
                                    <label class="form-label">ShortCode</label>
                                    <input type="text" class="form-control" name="shortcode" value="<?= isset($editJournal['shortcode']) ? h($editJournal['shortcode']) : '' ?>">
                                </div>
                                <div class="col-md-3 mb-3">
                                    <label class="form-label">Frequency</label>
                                    <select class="form-control" name="frequency">
                                        <option value="">-- Select --</option>
                                        <?php foreach ($frequency_options as $option): ?>
                                            <option value="<?= h($option) ?>" <?= (isset($editJournal['frequency']) && $editJournal['frequency'] === $option) ? 'selected' : '' ?>><?= h($option) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>
                        </div>

                        <div class="form-section">
                            <h6><i class="fas fa-chart-bar me-2"></i>Statistics & Performance</h6>
                            <div class="row">
                                <div class="col-md-3 mb-3">
                                    <label class="form-label">No. of issues till 2023</label>
                                    <input type="number" class="form-control" name="issues_till_2023" value="<?= isset($editJournal['issues_till_2023']) ? h($editJournal['issues_till_2023']) : '' ?>">
                                </div>
                                <div class="col-md-3 mb-3">
                                    <label class="form-label">Point of Revenue</label>
                                    <input type="text" class="form-control" name="point_of_revenue" value="<?= isset($editJournal['point_of_revenue']) ? h($editJournal['point_of_revenue']) : '' ?>">
                                </div>
                                <div class="col-md-3 mb-3">
                                    <label class="form-label">Avg. pages/Issue</label>
                                    <input type="number" class="form-control" name="avg_pages_per_issue" value="<?= isset($editJournal['avg_pages_per_issue']) ? h($editJournal['avg_pages_per_issue']) : '' ?>">
                                </div>
                                <div class="col-md-3 mb-3">
                                    <label class="form-label">Avg. articles/Issue</label>
                                    <input type="number" class="form-control" name="avg_articles_per_issue" value="<?= isset($editJournal['avg_articles_per_issue']) ? h($editJournal['avg_articles_per_issue']) : '' ?>">
                                </div>
                                <div class="col-md-4 mb-3">
                                    <label class="form-label">Avg. Article inflow in a Month</label>
                                    <input type="text" class="form-control" name="avg_article_inflow_month" value="<?= isset($editJournal['avg_article_inflow_month']) ? h($editJournal['avg_article_inflow_month']) : '' ?>">
                                </div>
                                <div class="col-md-4 mb-3">
                                    <label class="form-label">Performance</label>
                                    <select class="form-control" name="performance">
                                        <option value="">-- Select --</option>
                                        <?php foreach ($performance_options as $option): ?>
                                            <option value="<?= h($option) ?>" <?= (isset($editJournal['performance']) && $editJournal['performance'] === $option) ? 'selected' : '' ?>><?= h($option) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>
                        </div>

                        <div class="form-section">
                            <h6><i class="fas fa-tags me-2"></i>Classification & Details</h6>
                            <div class="row">
                                <div class="col-md-4 mb-3">
                                    <label class="form-label">Journal Specialty</label>
                                    <select class="form-control" name="specialty_id">
                                        <option value="">-- Select --</option>
                                        <?php foreach ($specialty_options as $specialty): ?>
                                            <option value="<?= $specialty['specialty_id'] ?>" <?= (isset($editJournal['specialty_id']) && $editJournal['specialty_id'] == $specialty['specialty_id']) ? 'selected' : '' ?>><?= h($specialty['specialty_name']) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-4 mb-3">
                                    <label class="form-label">Origin</label>
                                    <select class="form-control" name="origin">
                                        <option value="">-- Select --</option>
                                        <?php foreach ($origin_options as $option): ?>
                                            <option value="<?= h($option) ?>" <?= (isset($editJournal['origin']) && $editJournal['origin'] === $option) ? 'selected' : '' ?>><?= h($option) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-4 mb-3">
                                    <label class="form-label">Template</label>
                                    <select class="form-control" name="template_id">
                                        <option value="">-- Select --</option>
                                        <?php foreach ($template_options as $template): ?>
                                            <option value="<?= $template['template_id'] ?>" <?= (isset($editJournal['template_id']) && $editJournal['template_id'] == $template['template_id']) ? 'selected' : '' ?>><?= h($template['template_name']) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>
                        </div>

                        <div class="form-section">
                            <h6><i class="fas fa-users me-2"></i>Team & Contact Information</h6>
                            <div class="row">
                                <div class="col-md-3 mb-3">
                                    <label class="form-label">New TL</label>
                                    <input type="text" class="form-control" name="new_tl" value="<?= isset($editJournal['new_tl']) ? h($editJournal['new_tl']) : '' ?>">
                                </div>
                                <div class="col-md-3 mb-3">
                                    <label class="form-label">New PE</label>
                                    <input type="text" class="form-control" name="new_pe" value="<?= isset($editJournal['new_pe']) ? h($editJournal['new_pe']) : '' ?>">
                                </div>
                                <div class="col-md-3 mb-3">
                                    <label class="form-label">Manager</label>
                                    <select class="form-control" name="manager_id">
                                        <option value="">-- Select --</option>
                                        <?php foreach ($managers as $manager): ?>
                                            <option value="<?= $manager['team_id'] ?>" <?= (isset($editJournal['manager_id']) && $editJournal['manager_id'] == $manager['team_id']) ? 'selected' : '' ?>><?= h($manager['team_name']) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-3 mb-3">
                                    <label class="form-label">PE Contact Number</label>
                                    <input type="text" class="form-control" name="pe_contact_number" value="<?= isset($editJournal['pe_contact_number']) ? h($editJournal['pe_contact_number']) : '' ?>">
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">E-mail ID</label>
                                    <input type="email" class="form-control" name="email_id" value="<?= isset($editJournal['email_id']) ? h($editJournal['email_id']) : '' ?>">
                                </div>
                            </div>
                        </div>

                        <div class="form-section">
                            <h6><i class="fas fa-globe me-2"></i>Status & Online Presence</h6>
                            <div class="row">
                                <div class="col-md-4 mb-3">
                                    <label class="form-label">Status</label>
                                    <select class="form-control" name="status">
                                        <?php foreach ($status_options as $option): ?>
                                            <option value="<?= h($option) ?>" <?= (isset($editJournal['status']) && $editJournal['status'] === $option) ? 'selected' : '' ?>><?= h($option) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-4 mb-3">
                                    <label class="form-label">URL</label>
                                    <input type="url" class="form-control" name="url" value="<?= isset($editJournal['url']) ? h($editJournal['url']) : '' ?>">
                                </div>
                                <div class="col-md-4 mb-3">
                                    <div class="form-check form-switch mt-4">
                                        <input class="form-check-input" type="checkbox" id="live_on_ejp" name="live_on_ejp" <?= (isset($editJournal['live_on_ejp']) && $editJournal['live_on_ejp']) ? 'checked' : '' ?>>
                                        <label class="form-check-label" for="live_on_ejp">Live on eJP</label>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="d-flex gap-2 mt-4">
                            <button type="submit" class="btn btn-primary"><i class="fas fa-save me-1"></i> <?= $mode === 'edit' ? 'Update Journal' : 'Add Journal' ?></button>
                            <a href="pubcode.php" class="btn btn-outline-secondary">Cancel</a>
                        </div>
                    </form>
            </div></div>
        <?php else: ?>

            <!-- View Mode -->
            <div class="d-flex justify-content-between align-items-center mb-4">
                <div>
                    <h4><i class="fas fa-book me-2"></i>Pub Code</h4>
                    <p class="text-muted mb-0">Manage publication codes | Import Excel/CSV to bulk update</p>
                </div>
                <div>
                    <button type="button" class="btn btn-primary btn-sm me-2" onclick="showImportModal()">
                        <i class="fas fa-file-excel me-2"></i> Import Excel/CSV
                    </button>
                    <a href="pubcode.php?mode=add" class="btn btn-primary btn-sm"><i class="fas fa-plus me-1"></i> Add New Journal</a>
                </div>
            </div>

            <!-- Search Form -->
            <div class="card mb-4">
                <div class="card-body">
                    <form method="get" class="row g-3 align-items-center">
                        <div class="col-md-3">
                            <input type="text" name="q" class="form-control form-control-sm" placeholder="Sort by name, pubcode, email..." value="<?= h($q) ?>">
                        </div>
                        <div class="col-md-2">
                            <select name="status" class="form-select form-select-sm">
                                <option value="">Sort by Status</option>
                                <?php foreach ($status_options as $option): ?>
                                    <option value="<?= h($option) ?>" <?= $fstatus === $option ? 'selected' : '' ?>><?= h($option) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <select name="specialty" class="form-select form-select-sm">
                                <option value="">Sort by Specialties</option>
                                <?php foreach ($specialty_options as $specialty): ?>
                                    <option value="<?= $specialty['specialty_id'] ?>" <?= $fspecialty == $specialty['specialty_id'] ? 'selected' : '' ?>><?= h($specialty['specialty_name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <select name="origin" class="form-select form-select-sm">
                                <option value="">Sort by Origins</option>
                                <?php foreach ($origin_options as $option): ?>
                                    <option value="<?= h($option) ?>" <?= $forigin === $option ? 'selected' : '' ?>><?= h($option) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <select name="sort" class="form-select form-select-sm">
                                <option value="journal_name" <?= $sort === 'journal_name' ? 'selected' : '' ?>>Sort by Name</option>
                                <option value="pubcode" <?= $sort === 'pubcode' ? 'selected' : '' ?>>Sort by PubCode</option>
                                <option value="frequency" <?= $sort === 'frequency' ? 'selected' : '' ?>>Sort by Frequency</option>
                                <option value="created_date" <?= $sort === 'created_date' ? 'selected' : '' ?>>Sort by Created Date</option>
                            </select>
                        </div>
                        <!--div class="col-md-1">
                            <select name="dir" class="form-select form-select-sm">
                                <option value="asc" <?= $dir === 'asc' ? 'selected' : '' ?>>Asc</option>
                                <option value="desc" <?= $dir === 'desc' ? 'selected' : '' ?>>Desc</option>
                            </select>
                        </div-->
                        <div class="col-md-12 mt-2">
                            <button type="submit" class="btn btn-primary btn-sm me-2"><i class="fas fa-search me-1"></i> Filter</button>
                            <a href="pubcode.php" class="btn btn-secondary btn-sm"><i class="fas fa-times me-1"></i> Clear</a>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Journals Table in Exact Sequence -->
            <div class="card">
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th>S.No</th>
                                    <th>Journal Name</th>
                                    <th>Journal Ref</th>
                                    <th>PubCode</th>
                                    <th>ShortCode</th>
                                    <th>Frequency</th>
                                    <th>Issues till 2023</th>
                                    <th>Point of revenue</th>
                                    <th>Specialty</th>
                                    <th>Origin</th>
                                    <th>Avg pages/Issue</th>
                                    <th>Avg articles/Issue</th>
                                    <th>Performance</th>
                                    <th>Avg Article inflow/Month</th>
                                    <th>Template</th>
                                    <th>New TL</th>
                                    <th>New PE</th>
                                    <th>Manager</th>
                                    <th>E-mail ID</th>
                                    <th>PE Contact</th>
                                    <th>Status</th>
                                    <th>Live on eJP</th>
                                    <th>URL</th>
                                    <th width="120" class="text-center">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if ($journals && $journals->num_rows > 0): $i = 0; ?>
                                    <?php while ($row = $journals->fetch_assoc()): $i = $i + 1; ?>
                                        <tr>
                                            <td class="fw-bold"><?= $i; ?></td>
                                            <td class="journal-name-cell" title="<?= h((string)($row['journal_name'] ?? '')) ?>">
                                                <!-- FIXED: Swapped mb_strlen for standard strlen -->
                                                <?php $safeJName = (string)($row['journal_name'] ?? ''); echo h(strlen($safeJName) > 20 ? substr($safeJName, 0, 20) . '...' : $safeJName); ?>
                                            </td>
                                            <td><small><?= h($row['journal_ref']) ?></small></td>
                                            <td><span class="badge bg-secondary badge-small"><?= h($row['pubcode']) ?></span></td>
                                            <td><small><?= h($row['shortcode']) ?></small></td>
                                            <td><?php if (!empty($row['frequency'])): ?><span class="badge bg-info badge-small text-dark"><?= h($row['frequency']) ?></span><?php endif; ?></td>
                                            <td class="stats-cell"><?= (int)$row['issues_till_2023'] ?></td>
                                            <td><small><?= h($row['point_of_revenue']) ?></small></td>
                                            <td><?php if (!empty($row['specialty_name'])): ?><span class="badge bg-primary badge-small"><?= h($row['specialty_name']) ?></span><?php endif; ?></td>
                                            <td><?php if (!empty($row['origin'])): ?><span class="badge bg-warning text-dark badge-small"><?= h($row['origin']) ?></span><?php endif; ?></td>
                                            <td class="stats-cell"><?= (int)$row['avg_pages_per_issue'] ?></td>
                                            <td class="stats-cell"><?= (int)$row['avg_articles_per_issue'] ?></td>
                                            <td>
                                                <?php if (!empty($row['performance'])): ?>
                                                    <?php $perf_class = 'secondary'; if ($row['performance'] === 'Delayed') $perf_class = 'danger'; else if (in_array($row['performance'], ['Ontime', 'On Time'])) $perf_class = 'success'; ?>
                                                    <span class="badge bg-<?= $perf_class ?> badge-small"><?= h($row['performance']) ?></span>
                                                <?php endif; ?>
                                            </td>
                                            <td><small><?= h($row['avg_article_inflow_month']) ?></small></td>
                                            <td><small><?= h($row['template_name'] ?? '—') ?></small></td>
                                            <td><small><?= h($row['new_tl']) ?></small></td>
                                            <td><small><?= h($row['new_pe']) ?></small></td>
                                            <td><small><?= h($row['manager_name']) ?></small></td>
                                            <td class="email-cell" title="<?= h((string)($row['email_id'] ?? '')) ?>">
                                                <!-- FIXED: Swapped mb_strlen for standard strlen -->
                                                <?php $safeEmail = (string)($row['email_id'] ?? ''); echo $safeEmail !== '' ? h(strlen($safeEmail) > 15 ? substr($safeEmail, 0, 15) . '...' : $safeEmail) : '—'; ?>
                                            </td>
                                            <td><small><?= h($row['pe_contact_number']) ?></small></td>
                                            <td>
                                                <form method="post" style="display:inline;">
                                                    <input type="hidden" name="action" value="toggle_status">
                                                    <input type="hidden" name="journal_id" value="<?= (int)$row['journal_id'] ?>">
                                                    <button type="submit" class="btn btn-xs <?= strtolower(str_replace(' ', '', (string)$row['status'])) === 'active' ? 'btn-success' : 'btn-danger' ?>"><?= h($row['status']) ?></button>
                                                </form>
                                            </td>
                                            <td>
                                                <form method="post" style="display:inline;">
                                                    <input type="hidden" name="action" value="toggle_ejp">
                                                    <input type="hidden" name="journal_id" value="<?= (int)$row['journal_id'] ?>">
                                                    <button type="submit" class="btn btn-xs <?= $row['live_on_ejp'] ? 'btn-success' : 'btn-secondary' ?>"><?= $row['live_on_ejp'] ? 'Yes' : 'No' ?></button>
                                                </form>
                                            </td>
                                            <td class="url-cell" title="<?= h($row['url'] ?? '') ?>">
                                                <?php if (!empty($row['url'])): ?><a href="<?= h($row['url']) ?>" target="_blank" class="btn btn-link btn-sm"><i class="fas fa-external-link-alt"></i></a><?php else: ?><span class="text-muted">—</span><?php endif; ?>
                                            </td>
                                            <td class="text-center">
                                                <div class="btn-group btn-group-sm">
                                                    <a href="pubcode.php?mode=edit&id=<?= (int)$row['journal_id'] ?>" class="btn btn-primary"><i class="fas fa-edit"></i></a>
                                                    <form method="post" style="display:inline;" onsubmit="return confirm('Delete this journal?')">
                                                        <input type="hidden" name="action" value="delete">
                                                        <input type="hidden" name="journal_id" value="<?= (int)$row['journal_id'] ?>">
                                                        <button type="submit" class="btn btn-danger"><i class="fas fa-trash"></i></button>
                                                    </form>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endwhile; ?>
                                <?php else: ?>
                                    <tr>
                                        <td colspan="24" class="text-center py-5 text-muted"><i class="fas fa-book fa-3x mb-3"></i><h5>No journals found</h5></td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

        <?php endif; ?>
    </div>

    <!-- Import Modal -->
    <div id="importModal" class="modal-overlay">
        <div class="modal-box">
            <div class="modal-header">
                <h5><i class="fas fa-file-excel me-2"></i> Import Journals from Excel/CSV</h5>
                <span class="modal-close" onclick="hideImportModal()">&times;</span>
            </div>
            <form action="pubcode.php" method="post" enctype="multipart/form-data">
                <input type="hidden" name="action" value="import_excel">
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label fw-bold">Select Excel (.xls) or CSV File</label>
                        <input type="file" name="excel_file" class="form-control" accept=".csv, .xls, application/vnd.ms-excel" required>
                        <small class="text-muted">Maximum file size: 10MB</small>
                    </div>
                    <div class="mb-3">
                        <a href="?action=download_sample" class="btn btn-outline-success">
                            <i class="fas fa-download me-1"></i> Download Sample Excel (.xls)
                        </a>
                    </div>
                    <div class="alert alert-info">
                        <h6 class="fw-bold">Required File Sequence:</h6>
                        <p class="mb-0 small"><strong class="text-danger">Journal Name</strong>, Journal Ref, PubCode, ShortCode, Frequency, Issues till 2023, Revenue Point, Specialty, Origin, Avg Pages, Avg Articles, Performance, Article Inflow/Month, Template, New TL, New PE, Manager, Email, Contact, Status, Live on eJP, URL</p>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" onclick="hideImportModal()">Cancel</button>
                    <button type="submit" class="btn btn-primary">Import Data</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        function showImportModal() { document.getElementById('importModal').style.display = 'block'; }
        function hideImportModal() { document.getElementById('importModal').style.display = 'none'; }
        
        window.onclick = function(event) {
            var modal = document.getElementById('importModal');
            if (event.target === modal) modal.style.display = 'none';
        }

        setTimeout(function() {
            document.querySelectorAll('.alert').forEach(function(alert) {
                alert.style.transition = 'opacity 0.5s';
                alert.style.opacity = '0';
                setTimeout(function() { alert.style.display = 'none'; }, 500);
            });
        }, 5000);

        document.addEventListener('DOMContentLoaded', function() {
            var filterSelects = document.querySelectorAll('select[name="status"], select[name="specialty"], select[name="origin"], select[name="sort"], select[name="dir"]');
            filterSelects.forEach(function(select) {
                select.addEventListener('change', function() { this.closest('form').submit(); });
            });
        });
    </script>
</body>
</html>