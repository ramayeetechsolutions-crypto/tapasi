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

// Auto-fix database schema for status column (Converts ENUM to VARCHAR automatically)
$check_col = $mysqli->query("SHOW COLUMNS FROM ce LIKE 'status'");
if ($check_col) {
    $col_data = $check_col->fetch_assoc();
    if (strpos(strtolower($col_data['Type']), 'enum') !== false) {
        $mysqli->query("ALTER TABLE ce MODIFY COLUMN status VARCHAR(100) DEFAULT 'Need to Assign'");
    }
}

// Auto-add priority column if not exists
$check_priority = $mysqli->query("SHOW COLUMNS FROM ce LIKE 'priority'");
if ($check_priority && $check_priority->num_rows == 0) {
    $mysqli->query("ALTER TABLE ce ADD COLUMN priority VARCHAR(50) NULL DEFAULT NULL");
}

function h($s) {
    return htmlspecialchars((string)$s, ENT_QUOTES);
}

function formatDate($date, $format = 'd-m-Y') {
    if (!$date) return '';
    $dateObj = DateTime::createFromFormat('Y-m-d', $date);
    return $dateObj ? $dateObj->format($format) : $date;
}

// Get current user info
$current_user_id = $_SESSION['uid'] ?? 0;
$current_username = $_SESSION['username'] ?? '';
$role_name = $_SESSION['role_name'] ?? '';

// Get reporting manager for current user
$reporting_manager = '';
$current_user_name = '';
if ($current_user_id) {
    $stmt = $mysqli->prepare("SELECT employee_name, reporting_manager FROM employee_master WHERE employee_id = ?");
    if ($stmt) {
        $stmt->bind_param("i", $current_user_id);
        $stmt->execute();
        $stmt->bind_result($current_user_name, $reporting_manager);
        $stmt->fetch();
        $stmt->close();
    }
}

// Determine effective reporting manager (if user is TL/Admin without RM, use their own name)
$effective_manager = !empty($reporting_manager) ? $reporting_manager : $current_user_name;

// Get all employees under the same reporting manager with TM role
$tms = [];
if ($effective_manager) {
    $tm_query = "SELECT em.employee_id, em.employee_username, em.employee_name, em.user_role_id
                 FROM employee_master em 
                 JOIN user_role_master ur ON em.user_role_id = ur.role_id
                 WHERE (em.reporting_manager = ? OR em.reporting_manager = ?) 
                 AND em.status = 'Active' 
                 AND ur.role_name IN ('TM', 'TL', 'Admin')
                 ORDER BY em.employee_name";
    
    $stmt = $mysqli->prepare($tm_query);
    if ($stmt) {
        $stmt->bind_param("ss", $effective_manager, $current_user_name);
        $stmt->execute();
        $result = $stmt->get_result();
        while ($tm = $result->fetch_assoc()) {
            $tms[] = $tm;
        }
        $stmt->close();
    }
}

// --- START: Status Update AJAX ---
if (isset($_POST['ajax']) && $_POST['ajax'] == 'update_status') {
    header('Content-Type: application/json');
    $ce_id = (int)$_POST['ce_id'];
    $target_type = $_POST['type'] ?? 'ce';
    $new_status = trim($_POST['status']);

    // Fetch current status and times to combine and validate
    $get_stat = $mysqli->prepare("SELECT status, ce_start_time, ce_end_time, ref_start_time, ref_end_time, fqa_start_time, fqa_end_time FROM ce WHERE id = ?");
    $get_stat->bind_param("i", $ce_id);
    $get_stat->execute();
    $curr_data = $get_stat->get_result()->fetch_assoc();
    $curr_status = $curr_data['status'] ?? '';
    $get_stat->close();

    // Mandatory Time Validation for 'Completed' Status
    if ($new_status === 'Completed') {
        if ($target_type === 'ce' && (empty($curr_data['ce_start_time']) || empty($curr_data['ce_end_time']))) {
            echo json_encode(['success' => false, 'message' => 'Please fill both CE Start Time and End Time before marking as Completed.']);
            exit;
        } elseif ($target_type === 'ref' && (empty($curr_data['ref_start_time']) || empty($curr_data['ref_end_time']))) {
            echo json_encode(['success' => false, 'message' => 'Please fill both REF Start Time and End Time before marking as Completed.']);
            exit;
        } elseif ($target_type === 'fqa' && (empty($curr_data['fqa_start_time']) || empty($curr_data['fqa_end_time']))) {
            echo json_encode(['success' => false, 'message' => 'Please fill both FQA Start Time and End Time before marking as Completed.']);
            exit;
        }
    }

    $db_status = $curr_status; // default to current

    if ($target_type === 'ce') {
        if ($new_status === 'Completed') {
            $db_status = ($curr_status === 'REF Done') ? 'CE & REF Done' : 'CE Done';
        } elseif ($new_status === 'In Progress') {
            $db_status = ($curr_status === 'CE & REF Done') ? 'REF Done' : 'In Progress';
        }
    } elseif ($target_type === 'ref') {
        if ($new_status === 'Completed') {
            $db_status = ($curr_status === 'CE Done') ? 'CE & REF Done' : 'REF Done';
        } elseif ($new_status === 'In Progress') {
            $db_status = ($curr_status === 'CE & REF Done') ? 'CE Done' : 'In Progress';
        }
    } elseif ($target_type === 'fqa') {
        if ($new_status === 'Completed') {
            $db_status = 'Completed';
        }
    }

    $update_query = "UPDATE ce SET status = ?, last_updated = NOW() WHERE id = ?";
    if ($target_type === 'fqa' && $new_status === 'Completed') {
        $update_query = "UPDATE ce SET status = ?, ce_date = CURDATE(), last_updated = NOW() WHERE id = ?";
    }
    $stmt = $mysqli->prepare($update_query);
    $stmt->bind_param("si", $db_status, $ce_id);
    if ($stmt->execute()) {
        echo json_encode(['success' => true, 'status' => $db_status]);
    } else {
        echo json_encode(['success' => false, 'message' => $mysqli->error]);
    }
    exit;
}

// --- START: Inline Edit AJAX (For Counts & Time) ---
if (isset($_POST['ajax']) && $_POST['ajax'] == 'inline_edit') {
    header('Content-Type: application/json');
    $ce_id = (int)$_POST['ce_id'];
    $field = $_POST['field'];
    $val = $_POST['value'];
    
    // Allowed fields for security
    $allowed_fields = ['eq', 'images', 'tables_count', 'ref', 'ce_start_time', 'ce_end_time', 'ref_start_time', 'ref_end_time', 'fqa_start_time', 'fqa_end_time', 'word_count', 'remarks', 'priority'];
    if (in_array($field, $allowed_fields)) {
        $stmt = $mysqli->prepare("UPDATE ce SET $field = ?, last_updated = NOW() WHERE id = ?");
        $stmt->bind_param("si", $val, $ce_id);
        if ($stmt->execute()) {
            echo json_encode(['success' => true]);
        } else {
            echo json_encode(['success' => false, 'message' => $mysqli->error]);
        }
    } else {
        echo json_encode(['success' => false, 'message' => 'Invalid field update']);
    }
    exit;
}

// Handle AJAX quick assignment
if (isset($_POST['ajax']) && $_POST['ajax'] == 'quick_assign') {
    header('Content-Type: application/json');
    
    $ce_id = (int)$_POST['ce_id'];
    $job_id = (int)$_POST['job_id'];
    $type = $_POST['type']; // 'content', 'images', or 'fqa'
    $assigned_to = trim($_POST['assigned_to']);
    
    $response = ['success' => false, 'message' => ''];
    
    try {
        if (empty($assigned_to)) { throw new Exception('Please select a TM'); }
        
        $mysqli->begin_transaction();
        
        // Fetch CE record and real job_id
        $get_ce = $mysqli->prepare("SELECT id, job_id, status FROM ce WHERE id = ?");
        $get_ce->bind_param("i", $ce_id);
        $get_ce->execute();
        $res = $get_ce->get_result();
        if ($res->num_rows == 0) throw new Exception('CE record not found');
        $ce_row = $res->fetch_assoc();
        $real_job_id = (int)$ce_row['job_id'];
        $current_status = $ce_row['status'];
        $get_ce->close();

        // Target field in CE table
        // Note: For CE table, FQA assignee is stored in assigned_to (since assigned_fqa column does not exist)
        if ($type == 'content') {
            $update_ce = "UPDATE ce SET assigned_to_content = ?, status = CASE WHEN status = 'Need to Assign' THEN 'In Progress' ELSE status END, last_updated = NOW() WHERE id = ?";
            $stmt = $mysqli->prepare($update_ce);
            $stmt->bind_param("si", $assigned_to, $ce_id);
        } elseif ($type == 'images') {
            $update_ce = "UPDATE ce SET assigned_to_img = ?, status = CASE WHEN status = 'Need to Assign' THEN 'In Progress' ELSE status END, last_updated = NOW() WHERE id = ?";
            $stmt = $mysqli->prepare($update_ce);
            $stmt->bind_param("si", $assigned_to, $ce_id);
        } elseif ($type == 'fqa') {
            $update_ce = "UPDATE ce SET last_updated = NOW() WHERE id = ?";
            $stmt = $mysqli->prepare($update_ce);
            $stmt->bind_param("i", $ce_id);
        }

        if (!$stmt->execute()) {
            throw new Exception('Error updating CE: ' . $stmt->error);
        }
        $stmt->close();
        
        // Update jobs table
        if ($real_job_id > 0) {
            if ($type == 'content') {
                $stmt2 = $mysqli->prepare("UPDATE jobs SET assigned_ce = ?, due_date_ce = DATE_ADD(NOW(), INTERVAL 1 DAY) WHERE job_id = ?");
            } elseif ($type == 'images') {
                $stmt2 = $mysqli->prepare("UPDATE jobs SET assigned_le = ?, due_date_le = DATE_ADD(NOW(), INTERVAL 2 DAY) WHERE job_id = ?");
            } elseif ($type == 'fqa') {
                $stmt2 = $mysqli->prepare("UPDATE jobs SET assigned_to = ?, due_date_qc = DATE_ADD(NOW(), INTERVAL 2 DAY) WHERE job_id = ?");
            }
            if (isset($stmt2) && $stmt2) {
                $stmt2->bind_param("si", $assigned_to, $real_job_id);
                $stmt2->execute();
                $stmt2->close();
            }
        }
        
        $mysqli->commit();
        $response['success'] = true;
        
        foreach ($tms as $tm) {
            if ($tm['employee_username'] == $assigned_to) {
                $response['tm_name'] = $tm['employee_name'];
                break;
            }
        }
    } catch (Exception $e) {
        $mysqli->rollback();
        $response['message'] = $e->getMessage();
    }
    
    echo json_encode($response);
    exit;
}

// Handle regular form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'assign_tm') {
    $ce_id = (int)$_POST['ce_id'];
    $job_id = (int)$_POST['job_id'];
    $assigned_to_content = trim($_POST['assigned_to_content'] ?? '');
    $assigned_to_img = trim($_POST['assigned_to_img'] ?? '');
    $ce_date = $_POST['ce_date'] ?: date('Y-m-d');
    $notes = trim($_POST['notes'] ?? '');
    
    // Start transaction
    $mysqli->begin_transaction();
    
    try {
        // Update ce table with assignment
        $update_ce = "UPDATE ce SET 
                      assigned_to_content = ?,
                      assigned_to_img = ?,
                      status = CASE 
                          WHEN ? != '' OR ? != '' THEN 'In Progress'
                          ELSE status
                      END,
                      ce_date = ?,
                      last_updated = NOW()
                      WHERE id = ?";
        
        $stmt = $mysqli->prepare($update_ce);
        if (!$stmt) {
            throw new Exception('Error preparing statement: ' . $mysqli->error);
        }
        
        $stmt->bind_param("sssssi", $assigned_to_content, $assigned_to_img, $assigned_to_content, $assigned_to_img, $ce_date, $ce_id);
        
        if (!$stmt->execute()) {
            throw new Exception('Error executing update: ' . $stmt->error);
        }
        
        $stmt->close();
        
        // Update jobs table with assigned_ce (using content assignee as primary)
      /*  if (!empty($assigned_to_content)) {
            $update_jobs = "UPDATE jobs SET 
                            assigned_ce = ?,
                            due_date_ce = DATE_ADD(NOW(), INTERVAL 1 DAY)
                            WHERE job_id = ?";
            
            $stmt2 = $mysqli->prepare($update_jobs);
            if ($stmt2) {
                $stmt2->bind_param("si", $assigned_to_content, $job_id);
                $stmt2->execute();
                $stmt2->close();
            }
        }*/
        
            // Update jobs table assigned fields
        if (!empty($assigned_to_content)) {
            $update_jobs = "UPDATE jobs SET 
                            assigned_ce = ?,
                            due_date_ce = DATE_ADD(NOW(), INTERVAL 1 DAY)
                            WHERE job_id = ?";
            $stmt2 = $mysqli->prepare($update_jobs);
            if ($stmt2) {
                $stmt2->bind_param("si", $assigned_to_content, $job_id);
                $stmt2->execute();
                $stmt2->close();
            }
        }
        if (!empty($assigned_to_img)) {
            $update_jobs3 = "UPDATE jobs SET 
                             assigned_le = ?,
                             due_date_le = DATE_ADD(NOW(), INTERVAL 2 DAY)
                             WHERE job_id = ?";
            $stmt3 = $mysqli->prepare($update_jobs3);
            if ($stmt3) {
                $stmt3->bind_param("si", $assigned_to_img, $job_id);
                $stmt3->execute();
                $stmt3->close();
            }
        }


        // Commit transaction
        $mysqli->commit();
        
        $msg = "Job assigned successfully";
        $type = "success";
        
    } catch (Exception $e) {
        // Rollback transaction on error
        $mysqli->rollback();
        $msg = "Error assigning job: " . $e->getMessage();
        $type = "danger";
    }
    
    header("Location: ce_assign_tm.php?msg=" . urlencode($msg) . "&type=" . $type);
    exit;
}

// Get filter parameters
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$status_filter = isset($_GET['status']) ? trim($_GET['status']) : '';
$content_tm_filter = isset($_GET['content_tm']) ? trim($_GET['content_tm']) : '';
$images_tm_filter = isset($_GET['images_tm']) ? trim($_GET['images_tm']) : '';
$sort = isset($_GET['sort']) ? trim($_GET['sort']) : 'ce.created_at';
$dir = isset($_GET['dir']) && strtoupper($_GET['dir']) == 'ASC' ? 'ASC' : 'DESC';

// Build the query for CE records with status not completed
$where_conditions = ["ce.status != 'Completed'", "ce.status != 'Done'"];
$params = [];
$types = '';

if (!empty($search)) {
    $where_conditions[] = "(c.client_name LIKE ? OR ce.issue LIKE ? OR ce.articles LIKE ? OR ce.process LIKE ?)";
    $search_term = "%$search%";
    $params[] = $search_term;
    $params[] = $search_term;
    $params[] = $search_term;
    $params[] = $search_term;
    $types .= 'ssss';
}

if (!empty($status_filter)) {
    $where_conditions[] = "ce.status = ?";
    $params[] = $status_filter;
    $types .= 's';
}

if (!empty($content_tm_filter)) {
    $where_conditions[] = "ce.assigned_to_content = ?";
    $params[] = $content_tm_filter;
    $types .= 's';
}

if (!empty($images_tm_filter)) {
    $where_conditions[] = "ce.assigned_to_img = ?";
    $params[] = $images_tm_filter;
    $types .= 's';
}

$where_sql = implode(' AND ', $where_conditions);

// Main query
$ce_query = "SELECT ce.*, 
                    j.journal_issue, 
                    j.article,
                    j.process_id,
                    j.assigned_to,
                    j.due_date,
                    j.job_date,
                    j.notes as job_notes,
                    j.queries as job_queries,
                    j.word_count as job_word_count,
                    c.client_name,
                    c.team_client_name,
                    c.client_id,
                    DATEDIFF(j.due_date, CURDATE()) as days_remaining,
                    DATEDIFF(CURDATE(), DATE(j.job_date)) as tat_days
             FROM ce 
             LEFT JOIN jobs j ON ce.job_id = j.job_id
             LEFT JOIN client_master c ON j.client_id = c.client_id
             WHERE $where_sql
             ORDER BY $sort $dir";

$stmt = $mysqli->prepare($ce_query);
if (!empty($params)) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$ce_result = $stmt->get_result();
$stmt->close();

// Get unique statuses for filter dropdown
$status_query = "SELECT DISTINCT status FROM ce WHERE status != 'Completed' AND status != 'Done' ORDER BY status";
$status_result = $mysqli->query($status_query);

// Get unique clients for search dropdown
$clients_query = "SELECT DISTINCT c.client_name, c.team_client_name FROM ce JOIN jobs j ON ce.job_id = j.job_id JOIN client_master c ON j.client_id = c.client_id WHERE ce.status != 'Completed' AND ce.status != 'Done' ORDER BY c.team_client_name ASC";
$clients_result = $mysqli->query($clients_query);

// Get count statistics
$stats_query = "SELECT 
                    COUNT(*) as total,
                    SUM(CASE WHEN status = 'Need to Assign' THEN 1 ELSE 0 END) as need_assign,
                    SUM(CASE WHEN status IN ('In Progress', 'CE Done', 'REF Done', 'CE & REF Done') THEN 1 ELSE 0 END) as in_progress,
                    SUM(CASE WHEN assigned_to_content IS NOT NULL THEN 1 ELSE 0 END) as content_assigned,
                    SUM(CASE WHEN assigned_to_img IS NOT NULL THEN 1 ELSE 0 END) as images_assigned
                FROM ce 
                WHERE status != 'Completed' AND status != 'Done'";
$stats_result = $mysqli->query($stats_query);
$stats = $stats_result->fetch_assoc();

require 'header.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>CE Assign</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet" />
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet" />
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <!-- Select2 CSS for Search+Dropdown -->
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
    <style>
        /* Select2 Custom Style */
        .select2-container .select2-selection--single {
            height: 31px !important;
            border: 1px solid #dee2e6 !important;
            border-radius: 0.25rem !important;
        }
        .select2-container--default .select2-selection--single .select2-selection__rendered {
            line-height: 29px !important;
            font-size: 0.875rem !important;
            color: #212529 !important;
        }
        .select2-container--default .select2-selection--single .select2-selection__arrow {
            height: 29px !important;
        }

        * {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, sans-serif;
        }
        
        body {
            background-color: #f8fafc;
        }
        
        .page-header {
            background: white;
            border-bottom: 1px solid #e2e8f0;
            padding: 1rem 0;
            margin-bottom: 1.5rem;
            box-shadow: 0 1px 3px rgba(0,0,0,0.05);
        }
        
        .stats-card {
            border-radius: 12px;
            padding: 1rem;
            display: flex;
            align-items: center;
            gap: 1rem;
            border: 1px solid rgba(0,0,0,0.05);
            box-shadow: 0 2px 4px rgba(0,0,0,0.02);
            transition: transform 0.2s;
        }
        .stats-card:hover { transform: translateY(-2px); }
        
        /* New Full Box Colors */
        .stats-card.card-total { background: #e0f2fe; border-color: #bae6fd; }
        .stats-card.card-need { background: #fef3c7; border-color: #fde68a; }
        .stats-card.card-progress { background: #dbeafe; border-color: #bfdbfe; }
        .stats-card.card-content { background: #dcfce7; border-color: #bbf7d0; }
        .stats-card.card-images { background: #fae8ff; border-color: #fbcfe8; }
        
        .stats-icon {
            width: 48px;
            height: 48px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.5rem;
            background: rgba(255,255,255,0.6); /* White transparency for icon bg */
        }
        
        .stats-icon.total { color: #0369a1; }
        .stats-icon.need { color: #92400e; }
        .stats-icon.progress { color: #1e40af; }
        .stats-icon.content { color: #166534; }
        .stats-icon.images { color: #a21caf; }
        
        .stats-icon.total {
            background: #e0f2fe;
            color: #0369a1;
        }
        
        .stats-icon.need {
            background: #fef3c7;
            color: #92400e;
        }
        
        .stats-icon.progress {
            background: #dbeafe;
            color: #1e40af;
        }
        
        .stats-icon.content {
            background: #dcfce7;
            color: #166534;
        }
        
        .stats-icon.images {
            background: #fae8ff;
            color: #a21caf;
        }
        
        .stats-info h3 {
            font-size: 1.5rem;
            font-weight: 700;
            margin: 0;
            line-height: 1.2;
        }
        
        .stats-info p {
            margin: 0;
            color: #64748b;
            font-size: 0.8rem;
            font-weight: 500;
        }
        
        .filter-section {
            background: white;
            border-radius: 12px;
            padding: 1rem;
            margin-bottom: 1.5rem;
            border: 1px solid #e2e8f0;
        }
        
        .table-container {
            background: white;
            border-radius: 12px;
            border: 1px solid #e2e8f0;
            overflow: hidden;
            box-shadow: 0 2px 4px rgba(0,0,0,0.02);
        }
        
        .table {
            margin-bottom: 0;
        }
        
        .table thead th {
            background-color: #707981 !important; 
            color: white !important;
            font-weight: bold;
            font-size: 0.85rem;
            text-transform: uppercase;
            letter-spacing: 0.025em;
            padding: 0.8rem 0.5rem;
            border: 1px solid #5a6268 !important; /* Excel Border */
            white-space: nowrap;
        }
        
        .table tbody td {
            padding: 0.6rem 0.4rem;
            font-size: 0.85rem; /* Increased Font Size */
            color: #334155;
            vertical-align: middle;
            border: 1px solid #cbd5e1; /* Excel Border */
        }
        
        .table tbody tr:hover {
            background-color: #f8fafc;
        }
        
        .table tbody tr.assigned {
            background-color: #f0fdf4;
        }
        
        .status-badge {
            display: inline-block;
            padding: 0.2rem 0.4rem;
            border-radius: 9999px;
            font-size: 0.65rem;
            font-weight: 500;
        }
        
        .status-pending {
            background: #fef3c7;
            color: #92400e;
        }
        
        .status-progress {
            background: #dbeafe;
            color: #1e40af;
        }
        
        .tm-badge {
            display: inline-flex;
            align-items: center;
            gap: 0.25rem;
            padding: 0.2rem 0.4rem;
            border-radius: 4px;
            font-size: 0.7rem;
            background: #f1f5f9;
            color: #475569;
            white-space: nowrap;
        }
        
        .tm-badge.content {
            background: #dcfce7;
            color: #166534;
        }
        
        .tm-badge.images {
            background: #fae8ff;
            color: #a21caf;
        }
        
        .tm-badge i {
            font-size: 0.6rem;
        }
        
        .assignment-dropdown {
            min-width: 140px;
        }
        
        .assign-select {
            font-size: 0.7rem;
            padding: 0.2rem 0.4rem;
            height: auto;
            width: 100%;
            border-radius: 4px;
            border: 1.5px solid #e2e8f0;
        }
        
        .assign-select:focus {
            border-color: #667eea;
            outline: none;
        }
        
        .btn-group-sm .btn {
            padding: 0.2rem 0.4rem;
            font-size: 0.7rem;
        }
        
        .text-truncate {
            max-width: 120px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        
        .sort-link {
            color: #1e293b;
            text-decoration: none;
            display: flex;
            align-items: center;
            gap: 0.25rem;
        }
        
        .sort-link:hover {
            color: #667eea;
        }
        
        .sort-link i {
            font-size: 0.6rem;
        }
        
        .empty-state {
            text-align: center;
            padding: 3rem;
        }
        
        .empty-state i {
            font-size: 3rem;
            color: #cbd5e1;
            margin-bottom: 1rem;
        }
        
        .due-date {
            font-weight: 500;
        }
        
        .due-date.urgent {
            color: #dc2626;
        }
        
        .due-date.warning {
            color: #d97706;
        }
        
        .due-date.normal {
            color: #059669;
        }
        
        .alert {
            border-radius: 12px;
            border: none;
            padding: 0.75rem 1rem;
            margin-bottom: 1rem;
        }
        
        .tooltip-inner {
            font-size: 0.7rem;
            padding: 0.25rem 0.5rem;
        }
        
        .assignment-cell {
            min-width: 130px;
        }
        
        .loading-overlay {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(255,255,255,0.8);
            display: none;
            justify-content: center;
            align-items: center;
            z-index: 9999;
        }
        
        .loading-overlay.active {
            display: flex;
        }
        
        .loading-spinner {
            background: white;
            padding: 2rem;
            border-radius: 12px;
            box-shadow: 0 4px 6px rgba(0,0,0,0.1);
            text-align: center;
        }
        
        .notification {
            position: fixed;
            top: 20px;
            right: 20px;
            z-index: 10000;
            min-width: 300px;
            animation: slideIn 0.3s ease;
        }
        
        @keyframes slideIn {
            from {
                transform: translateX(100%);
                opacity: 0;
            }
            to {
                transform: translateX(0);
                opacity: 1;
            }
        }
        /* Dynamic Workflow Badges (Purple for In Progress, Green for Completed) */
        .tm-badge.in-progress {
            background-color: #f3e8ff !important;
            color: #7e22ce !important;
            border: 1px solid #d8b4fe !important;
            font-weight: 600;
        }
        .tm-badge.completed {
            background-color: #dcfce7 !important;
            color: #15803d !important;
            border: 1px solid #86efac !important;
            font-weight: 600;
        }
        .tm-badge.pending {
            background-color: #f1f5f9;
            color: #64748b;
        }
    </style>
</head>
<body>
    <br><br><br>
    <!-- Loading Overlay -->
    <div class="loading-overlay" id="loadingOverlay">
        <div class="loading-spinner">
            <div class="spinner-border text-primary mb-2" role="status">
                <span class="visually-hidden">Loading...</span>
            </div>
            <p class="mb-0 small">Processing assignment...</p>
        </div>
    </div>

    <!-- Notification Container -->
    <div id="notificationContainer" style="position: fixed; top: 20px; right: 20px; z-index: 10000;"></div>

    <div class="page-header">
        <div class="container-fluid px-4">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <h4 class="mb-1" style="font-weight: 700; color: #1e293b;">
                        <i class="fas fa-table me-2" style="color: #667eea;"></i>
                        CE Assign
                    </h4>
                    <!-- <p class="mb-0 text-muted small">
                        Assign Content and Images separately to team members under <?= h($reporting_manager) ?>
                    </p> -->
                </div>
                <div>
                    <a href="jobs.php" class="btn btn-outline-secondary btn-sm me-2">
                        <i class="fas fa-arrow-left me-1"></i> Back to Jobs
                    </a>
                    <span class="badge bg-primary">
                        <i class="fas fa-users me-1"></i> <?= count($tms) ?> Team Members
                    </span>
                </div>
            </div>
        </div>
    </div>

    <div class="container-fluid px-4">
        <?php if (!empty($_GET['msg'])): ?>
            <div class="alert alert-<?= h($_GET['type'] ?? 'info') ?> alert-dismissible fade show">
                <i class="fas <?= ($_GET['type'] ?? 'info') === 'success' ? 'fa-check-circle' : 'fa-exclamation-circle' ?> me-2"></i>
                <?= h($_GET['msg']) ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <!-- Statistics Cards -->
        <div class="row g-3 mb-4">
            <div class="col-md-2">
                <div class="stats-card card-total">
                    <div class="stats-icon total">
                        <i class="fas fa-clipboard-list"></i>
                    </div>
                    <div class="stats-info">
                        <h3><?= $stats['total'] ?? 0 ?></h3>
                        <p>Total Pending</p>
                    </div>
                </div>
            </div>
            <div class="col-md-2">
                <div class="stats-card card-need">
                    <div class="stats-icon need">
                        <i class="fas fa-hourglass-start"></i>
                    </div>
                    <div class="stats-info">
                        <h3><?= $stats['need_assign'] ?? 0 ?></h3>
                        <p>Need to Assign</p>
                    </div>
                </div>
            </div>
            <div class="col-md-2">
                <div class="stats-card card-progress">
                    <div class="stats-icon progress">
                        <i class="fas fa-spinner"></i>
                    </div>
                    <div class="stats-info">
                        <h3><?= $stats['in_progress'] ?? 0 ?></h3>
                        <p>In Progress</p>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="stats-card card-content">
                    <div class="stats-icon content">
                        <i class="fas fa-file-alt"></i>
                    </div>
                    <div class="stats-info">
                        <h3><?= $stats['content_assigned'] ?? 0 ?></h3>
                        <p>Content Assigned</p>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="stats-card card-images">
                    <div class="stats-icon images">
                        <i class="fas fa-image"></i>
                    </div>
                    <div class="stats-info">
                        <h3><?= $stats['images_assigned'] ?? 0 ?></h3>
                        <p>Images Assigned</p>
                    </div>
                </div>
            </div>
        </div>

        <!-- Filter Section -->
        <div class="filter-section">
            <form method="get" class="row g-2 align-items-end">
                <div class="col-md-3">
                    <label class="form-label small">Search Client</label>
                    <select name="search" id="clientSearchDropdown" class="form-select form-select-sm">
                        <option value="">All Clients...</option>
                        <?php if ($clients_result): ?>
                            <?php while ($cli = $clients_result->fetch_assoc()): 
                                // Show team_client_name if available, else fallback to client_name
                                $display_name = !empty($cli['team_client_name']) ? $cli['team_client_name'] : $cli['client_name'];
                            ?>
                                <option value="<?= h($cli['client_name']) ?>" <?= $search == $cli['client_name'] ? 'selected' : '' ?>>
                                    <?= h($display_name) ?>
                                </option>
                            <?php endwhile; ?>
                        <?php endif; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label small">Status</label>
                    <select name="status" class="form-select form-select-sm">
                        <option value="">All Status</option>
                        <?php if ($status_result): ?>
                            <?php while ($status = $status_result->fetch_assoc()): ?>
                                <option value="<?= h($status['status']) ?>" 
                                    <?= $status_filter == $status['status'] ? 'selected' : '' ?>>
                                    <?= h($status['status']) ?>
                                </option>
                            <?php endwhile; ?>
                        <?php endif; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label small">Content TM</label>
                    <select name="content_tm" class="form-select form-select-sm">
                        <option value="">All</option>
                        <?php foreach ($tms as $tm): ?>
                            <option value="<?= h($tm['employee_username']) ?>" 
                                <?= $content_tm_filter == $tm['employee_username'] ? 'selected' : '' ?>>
                                <?= h($tm['employee_name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label small">Images TM</label>
                    <select name="images_tm" class="form-select form-select-sm">
                        <option value="">All</option>
                        <?php foreach ($tms as $tm): ?>
                            <option value="<?= h($tm['employee_username']) ?>" 
                                <?= $images_tm_filter == $tm['employee_username'] ? 'selected' : '' ?>>
                                <?= h($tm['employee_name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label small">Sort By</label>
                    <select name="sort" class="form-select form-select-sm">
                        <option value="ce.created_at" <?= $sort == 'ce.created_at' ? 'selected' : '' ?>>Created Date</option>
                        <option value="c.client_name" <?= $sort == 'c.client_name' ? 'selected' : '' ?>>Client Name</option>
                        <option value="ce.status" <?= $sort == 'ce.status' ? 'selected' : '' ?>>Status</option>
                        <option value="j.due_date" <?= $sort == 'j.due_date' ? 'selected' : '' ?>>Due Date</option>
                    </select>
                </div>
                <!-- <div class="col-md-1">
                    <label class="form-label small">Dir</label>
                    <select name="dir" class="form-select form-select-sm">
                        <option value="DESC" <?= $dir == 'DESC' ? 'selected' : '' ?>>Desc</option>
                        <option value="ASC" <?= $dir == 'ASC' ? 'selected' : '' ?>>Asc</option>
                    </select>
                </div> -->
                <div class="col-12 mt-2">
                    <button type="submit" class="btn btn-primary btn-sm">
                        <i class="fas fa-filter me-1"></i> Apply Filters
                    </button>
                    <a href="ce_assign_tm.php" class="btn btn-outline-secondary btn-sm">
                        <i class="fas fa-undo me-1"></i> Reset
                    </a>
                </div>
            </form>
        </div>

        <!-- Main Grid/Table -->
        <div class="table-container">
            <div class="table-responsive">
                <table class="table table-hover table-bordered" style="min-width: 1900px; border-collapse: collapse;">
                    <thead>
                        <tr>
                            <th>S.No</th>
                            <th>Received Date</th>
                            <th>Client</th>
                            <th>Issue</th>
                            <th>Articles</th>
                            <th>EQ</th>
                            <th>Images</th>
                            <th>Tables</th>
                            <th>Process</th>
                            <th>Due Date</th>
                            <th>Remarks</th>
                            <th>CE Assignment</th>
                            <th>Word Count</th>
                            <th>REF Assignment</th>
                            <th>REF Count</th>
                            <th>FQA Assignment</th>
                            <th>TAT</th>
                            <th>Priority</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($ce_result && $ce_result->num_rows > 0): $i=0;?>
                            <?php while ($row = $ce_result->fetch_assoc()): $i=$i+1;
                                $has_content = !empty($row['assigned_to_content']);
                                $has_images = !empty($row['assigned_to_img']);
                                $row_class = ($has_content || $has_images) ? 'assigned' : '';
                                
                                // Due date color coding
                                $due_class = '';
                                if ($row['days_remaining'] < 2) {
                                    $due_class = 'urgent';
                                } elseif ($row['days_remaining'] < 4) {
                                    $due_class = 'warning';
                                } else {
                                    $due_class = 'normal';
                                }
                            ?>
                                <tr class="<?= $row_class ?>" id="row-<?= $row['id'] ?>">
                                    <!-- S.No -->
                                    <td><?= $i ?></td>

                                    <!-- Received Date -->
                                    <td>
                                        <span class="fw-500 text-muted"><?= formatDate($row['job_date'] ?? '') ?></span>
                                    </td>

                                    <!-- 1. Client -->
                                    <td>
                                        <span class="fw-500">
                                            <?= h(!empty($row['team_client_name']) ? $row['team_client_name'] : ($row['client_name'] ?? 'N/A')) ?>
                                        </span>
                                    </td>

                                    <!-- 2. Issue -->
                                    <td>
                                        <span class="text-truncate d-block" title="<?= h($row['issue'] ?? '') ?>">
                                            <?= h($row['issue'] ?? 'N/A') ?>
                                        </span>
                                    </td>

                                    <!-- 3. Articles -->
                                    <td>
                                        <span class="text-truncate d-block" title="<?= h($row['articles'] ?? '') ?>">
                                            <?= h($row['articles'] ?? 'N/A') ?>
                                        </span>
                                    </td>

                                    <!-- 4. EQ (Editable) -->
                                    <td>
                                        <input type="number" class="form-control form-control-sm p-1 text-center" style="width: 50px; font-size:11px;" value="<?= h($row['eq'] ?? '0') ?>" onchange="updateInlineField(<?= $row['id'] ?>, 'eq', this.value)">
                                    </td>

                                    <!-- 5. Images (Editable) -->
                                    <td>
                                        <input type="number" class="form-control form-control-sm p-1 text-center" style="width: 50px; font-size:11px;" value="<?= h($row['images'] ?? '0') ?>" onchange="updateInlineField(<?= $row['id'] ?>, 'images', this.value)">
                                    </td>

                                    <!-- 6. Tables (Editable) -->
                                    <td>
                                        <input type="number" class="form-control form-control-sm p-1 text-center" style="width: 50px; font-size:11px;" value="<?= h($row['tables_count'] ?? '0') ?>" onchange="updateInlineField(<?= $row['id'] ?>, 'tables_count', this.value)">
                                    </td>

                                    <!-- 7. Process -->
                                    <td>
                                        <span class="badge bg-secondary"><?= h($row['process'] ?? 'N/A') ?></span>
                                    </td>

                                    <!-- 8. Due Date -->
                                    <td>
                                        <?php if ($row['due_date']): ?>
                                            <span class="due-date <?= $due_class ?>" title="<?= $row['days_remaining'] ?> days remaining">
                                                <?= formatDate($row['due_date']) ?>
                                            </span>
                                        <?php else: ?>
                                            <span class="text-muted">-</span>
                                        <?php endif; ?>
                                    </td>

                                    <!-- Remarks Dropdown -->
                                    <td>
                                        <select class="form-select form-select-sm p-1" style="font-size: 11px; width: 110px;" onchange="updateInlineField(<?= $row['id'] ?>, 'remarks', this.value)">
                                            <option value="">Select</option>
                                            <?php 
                                            $remarks_options = ['Hold', 'Chk Highlights', 'Doubt', 'Query', 'Not in server', 'Not in drive', 'Take drive', 'Chk', 'Query Sent', 'Chk mail', 'Urgent', 'Send Today', 'Client priority', 'AP team inform', 'PAG team inform', 'Update AOP', 'Invoice', 'Before 11 AM', 'Before 1 PM', 'Before 4 PM', 'Before 5 PM'];
                                            foreach($remarks_options as $opt) {
                                                $selected = (isset($row['remarks']) && $row['remarks'] === $opt) ? 'selected' : '';
                                                echo "<option value=\"".h($opt)."\" $selected>".h($opt)."</option>";
                                            }
                                            ?>
                                        </select>
                                    </td>

                                    <!-- 9. CE Assignment (Content) -->
                                    <td class="assignment-cell">
                                        <?php 
                                            $ce_done = in_array($row['status'], ['CE Done', 'CE & REF Done', 'Completed']);
                                            $ce_inprogress = (!$ce_done && !empty($row['assigned_to_content']));

                                            $ce_curr_status = 'Need to Assign';
                                            $ce_badge_cls = '';
                                            $ce_icon = 'fa-file-alt';

                                            if ($ce_done) {
                                                $ce_curr_status = 'Completed';
                                                $ce_badge_cls = 'completed'; // Green
                                            } elseif ($ce_inprogress) {
                                                $ce_curr_status = 'In Progress';
                                                $ce_badge_cls = 'in-progress'; // Purple
                                            }
                                        ?>
                                        <div class="mb-1">
                                            <select class="form-select form-select-sm p-0 px-1" 
                                                    style="font-size: 11px; width: 130px; height: 22px; border-color: #ccc; background-color: #f9f9f9;" 
                                                    onchange="updateStatus(<?= $row['id'] ?>, 'ce', this.value)">
                                                <option value="Need to Assign" <?= ($ce_curr_status == 'Need to Assign') ? 'selected' : '' ?>>Need to Assign</option>
                                                <option value="In Progress" <?= ($ce_curr_status == 'In Progress') ? 'selected' : '' ?>>In Progress</option>
                                                <option value="Completed" <?= ($ce_curr_status == 'Completed') ? 'selected' : '' ?>>Completed</option>
                                            </select>
                                        </div>

                                        <?php if (!empty($row['assigned_to_content'])): ?>
                                            <div class="tm-badge <?= $ce_badge_cls ?> mb-1" title="CE TM">
                                                <i class="fas <?= $ce_icon ?>"></i> <?= h($row['assigned_to_content']) ?>
                                            </div>
                                            <small class="text-muted d-block mb-1">
                                                <?php foreach ($tms as $tm) { if ($tm['employee_username'] == $row['assigned_to_content']) { echo h($tm['employee_name']); break; } } ?>
                                            </small>
                                        <?php endif; ?>
                                        
                                        <select class="assign-select mt-1 content-select-<?= $row['id'] ?>" onchange="quickAssign(<?= $row['id'] ?>, <?= $row['job_id'] ?>, 'content', this)">
                                            <option value=""><?= !empty($row['assigned_to_content']) ? 'Change Content TM' : 'Assign Content' ?></option>
                                            <?php foreach ($tms as $tm): ?>
                                                <option value="<?= h($tm['employee_username']) ?>" data-name="<?= h($tm['employee_name']) ?>" <?= ($row['assigned_to_content'] == $tm['employee_username']) ? 'selected' : '' ?>>
                                                    <?= h($tm['employee_name']) ?> (<?= h($tm['employee_username']) ?>)
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                        <!-- CE Time Inputs -->
                                        <div class="d-flex gap-1 mt-2">
                                            <input type="time" class="form-control form-control-sm p-1" style="font-size:10px; width:50%; cursor:pointer;" title="CE Start" value="<?= !empty($row['ce_start_time']) ? date('H:i', strtotime($row['ce_start_time'])) : '' ?>" readonly onclick="const t = new Date().toTimeString().slice(0,5); this.value=t; updateInlineField(<?= $row['id'] ?>, 'ce_start_time', t, this);">
                                            <input type="time" class="form-control form-control-sm p-1" style="font-size:10px; width:50%; cursor:pointer;" title="CE End" value="<?= !empty($row['ce_end_time']) ? date('H:i', strtotime($row['ce_end_time'])) : '' ?>" readonly onclick="const t = new Date().toTimeString().slice(0,5); this.value=t; updateInlineField(<?= $row['id'] ?>, 'ce_end_time', t, this);">
                                        </div>
                                    </td>

                                    <!-- 10. Word Count -->
                                    <td>
                                         <?php 
                                         $word_count = 0;
                                         if (!empty($row['word_count']) && (int)$row['word_count'] > 0) {
                                             $word_count = (int)$row['word_count'];
                                         } elseif (!empty($row['job_word_count']) && (int)$row['job_word_count'] > 0) {
                                             $word_count = (int)$row['job_word_count'];
                                         }
                                         ?>
                                         <input type="number" class="form-control form-control-sm p-1 text-center fw-bold text-primary" style="width: 70px; font-size:13px; border: 1px solid #667eea;" value="<?= h($word_count) ?>" onchange="updateInlineField(<?= $row['id'] ?>, 'word_count', this.value)">
                                    </td>

                                    <!-- 11. REF Assignment (Reference/Images) -->
                                    <td class="assignment-cell">
                                        <?php 
                                            $ref_done = in_array($row['status'], ['REF Done', 'CE & REF Done', 'Completed']);
                                            $ref_inprogress = (!$ref_done && !empty($row['assigned_to_img']));

                                            $ref_curr_status = 'Need to Assign';
                                            $ref_badge_cls = '';

                                            if ($ref_done) {
                                                $ref_curr_status = 'Completed';
                                                $ref_badge_cls = 'completed'; // Green
                                            } elseif ($ref_inprogress) {
                                                $ref_curr_status = 'In Progress';
                                                $ref_badge_cls = 'in-progress'; // Purple
                                            }
                                        ?>
                                        <div class="mb-1">
                                            <select class="form-select form-select-sm p-0 px-1" 
                                                    style="font-size: 11px; width: 130px; height: 22px; border-color: #ccc; background-color: #f9f9f9;" 
                                                    onchange="updateStatus(<?= $row['id'] ?>, 'ref', this.value)">
                                                <option value="Need to Assign" <?= ($ref_curr_status == 'Need to Assign') ? 'selected' : '' ?>>Need to Assign</option>
                                                <option value="In Progress" <?= ($ref_curr_status == 'In Progress') ? 'selected' : '' ?>>In Progress</option>
                                                <option value="Completed" <?= ($ref_curr_status == 'Completed') ? 'selected' : '' ?>>Completed</option>
                                            </select>
                                        </div>

                                        <?php if (!empty($row['assigned_to_img'])): ?>
                                            <div class="tm-badge <?= $ref_badge_cls ?> mb-1" title="REF TM">
                                                <i class="fas fa-image"></i> <?= h($row['assigned_to_img']) ?>
                                            </div>
                                            <small class="text-muted d-block mb-1">
                                                <?php foreach ($tms as $tm) { if ($tm['employee_username'] == $row['assigned_to_img']) { echo h($tm['employee_name']); break; } } ?>
                                            </small>
                                        <?php endif; ?>
                                        
                                        <select class="assign-select mt-1 images-select-<?= $row['id'] ?>" onchange="quickAssign(<?= $row['id'] ?>, <?= $row['job_id'] ?>, 'images', this)">
                                            <option value=""><?= !empty($row['assigned_to_img']) ? 'Change REF TM' : 'Assign REF' ?></option>
                                            <?php foreach ($tms as $tm): ?>
                                                <option value="<?= h($tm['employee_username']) ?>" data-name="<?= h($tm['employee_name']) ?>" <?= ($row['assigned_to_img'] == $tm['employee_username']) ? 'selected' : '' ?>>
                                                    <?= h($tm['employee_name']) ?> (<?= h($tm['employee_username']) ?>)
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                        <!-- REF Time Inputs -->
                                        <div class="d-flex gap-1 mt-2">
                                            <input type="time" class="form-control form-control-sm p-1" style="font-size:10px; width:50%; cursor:pointer;" title="REF Start" value="<?= !empty($row['ref_start_time']) ? date('H:i', strtotime($row['ref_start_time'])) : '' ?>" readonly onclick="const t = new Date().toTimeString().slice(0,5); this.value=t; updateInlineField(<?= $row['id'] ?>, 'ref_start_time', t, this);">
                                            <input type="time" class="form-control form-control-sm p-1" style="font-size:10px; width:50%; cursor:pointer;" title="REF End" value="<?= !empty($row['ref_end_time']) ? date('H:i', strtotime($row['ref_end_time'])) : '' ?>" readonly onclick="const t = new Date().toTimeString().slice(0,5); this.value=t; updateInlineField(<?= $row['id'] ?>, 'ref_end_time', t, this);">
                                        </div>
                                    </td>

                                    <!-- 12. REF Count (Editable) -->
                                    <td>
                                        <input type="number" class="form-control form-control-sm p-1 text-center" style="width: 50px; font-size:11px;" value="<?= h($row['ref'] ?? '0') ?>" onchange="updateInlineField(<?= $row['id'] ?>, 'ref', this.value)">
                                    </td>

                                    <!-- 13. FQA Assignment -->
                                    <td class="assignment-cell">
                                        <?php 
                                            $fqa_user = $row['assigned_to'] ?? '';
                                            $fqa_done = ($row['status'] === 'Completed');
                                            $fqa_inprogress = (!$fqa_done && !empty($fqa_user));

                                            $fqa_curr_status = 'Need to Assign';
                                            $fqa_badge_cls = '';

                                            if ($fqa_done) {
                                                $fqa_curr_status = 'Completed';
                                                $fqa_badge_cls = 'completed'; // Green
                                            } elseif ($fqa_inprogress) {
                                                $fqa_curr_status = 'In Progress';
                                                $fqa_badge_cls = 'in-progress'; // Purple
                                            }
                                        ?>
                                        
                                        <div class="mb-1">
                                            <select class="form-select form-select-sm p-0 px-1" 
                                                    style="font-size: 11px; width: 130px; height: 22px; border-color: #ccc; background-color: #f9f9f9;" 
                                                    onchange="updateStatus(<?= $row['id'] ?>, 'fqa', this.value)">
                                                <option value="Need to Assign" <?= ($fqa_curr_status == 'Need to Assign') ? 'selected' : '' ?>>Need to Assign</option>
                                                <option value="In Progress" <?= ($fqa_curr_status == 'In Progress') ? 'selected' : '' ?>>In Progress</option>
                                                <option value="Completed" <?= ($fqa_curr_status == 'Completed') ? 'selected' : '' ?>>Completed</option>
                                            </select>
                                        </div>

                                        <!-- <?php if (!empty($fqa_user)): ?>
                                            <div class="tm-badge <?= $fqa_badge_cls ?> mb-1" title="FQA TM">
                                                <i class="fas fa-search"></i> <?= h($fqa_user) ?>
                                            </div>
                                            <small class="text-muted d-block mb-1">
                                                <?php foreach ($tms as $tm) { if ($tm['employee_username'] == $fqa_user) { echo h($tm['employee_name']); break; } } ?>
                                            </small>
                                        <?php endif; ?> -->
                                        
                                        <select class="assign-select mt-1 fqa-select-<?= $row['id'] ?>" onchange="quickAssign(<?= $row['id'] ?>, <?= $row['job_id'] ?>, 'fqa', this)">
                                            <option value=""><?= !empty($fqa_user) ? 'Change FQA TM' : 'Assign FQA' ?></option>
                                            <?php foreach ($tms as $tm): ?>
                                                <option value="<?= h($tm['employee_username']) ?>" data-name="<?= h($tm['employee_name']) ?>" <?= ($fqa_user == $tm['employee_username']) ? 'selected' : '' ?>>
                                                    <?= h($tm['employee_name']) ?> (<?= h($tm['employee_username']) ?>)
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                        <!-- FQA Time Inputs -->
                                        <div class="d-flex gap-1 mt-2">
                                            <input type="time" class="form-control form-control-sm p-1" style="font-size:10px; width:50%; cursor:pointer;" title="FQA Start" value="<?= !empty($row['fqa_start_time']) ? date('H:i', strtotime($row['fqa_start_time'])) : '' ?>" readonly onclick="const t = new Date().toTimeString().slice(0,5); this.value=t; updateInlineField(<?= $row['id'] ?>, 'fqa_start_time', t, this);">
                                            <input type="time" class="form-control form-control-sm p-1" style="font-size:10px; width:50%; cursor:pointer;" title="FQA End" value="<?= !empty($row['fqa_end_time']) ? date('H:i', strtotime($row['fqa_end_time'])) : '' ?>" readonly onclick="const t = new Date().toTimeString().slice(0,5); this.value=t; updateInlineField(<?= $row['id'] ?>, 'fqa_end_time', t, this);">
                                        </div>
                                        </td>

                                    <!-- 16. TAT -->
                                    <td>
                                        <?php 
                                            if (!empty($row['job_date'])) {
                                                $tat_days = (int)$row['tat_days'];
                                                if ($tat_days <= 2) {
                                                    echo '<span class="badge bg-success">' . $tat_days . ' Days</span>';
                                                } else {
                                                    echo '<span class="badge bg-danger">' . $tat_days . ' Days</span>';
                                                }
                                            } else {
                                                echo '<span class="text-muted">-</span>';
                                            }
                                        ?>
                                    </td>
                                    
                                    <!-- 17. Priority -->
                                    <td>
                                        <select class="form-select form-select-sm p-1" style="font-size: 11px; width: 110px;" onchange="updateInlineField(<?= $row['id'] ?>, 'priority', this.value)">
                                            <option value="">Select Priority</option>
                                            <option value="Client Priority" <?= ($row['priority'] ?? '') == 'Client Priority' ? 'selected' : '' ?>>Client Priority</option>
                                            <option value="Urgent" <?= ($row['priority'] ?? '') == 'Urgent' ? 'selected' : '' ?>>Urgent</option>
                                        </select>
                                    </td>
                                </tr>
                            <?php endwhile; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="16" class="empty-state">
                                    <i class="fas fa-check-circle"></i>
                                    <h6>No Pending Assignments</h6>
                                    <p class="text-muted small">All CE jobs have been assigned or completed.</p>
                                </td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Pagination Info -->
        <?php if ($ce_result && $ce_result->num_rows > 0): ?>
            <div class="row mt-3">
                <div class="col-12">
                    <div class="d-flex justify-content-between align-items-center">
                        <small class="text-muted">
                            <i class="fas fa-list me-1"></i> Showing <?= $ce_result->num_rows ?> job(s)
                        </small>
                        <!-- <small class="text-muted">
                            Reporting Manager: <strong><?= h($reporting_manager) ?></strong>
                        </small> -->
                    </div>
                </div>
            </div>
        <?php endif; ?>
    </div>

    <!-- Full Assignment Modal -->
    <div class="modal fade" id="assignmentModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">
                        <i class="fas fa-user-plus me-2"></i>Complete Assignment Details
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body" id="modalBody">
                    <!-- Will be loaded via AJAX -->
                </div>
            </div>
        </div>
    </div>

    <script>
        // Initialize tooltips
        var tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'))
        var tooltipList = tooltipTriggerList.map(function (tooltipTriggerEl) {
            return new bootstrap.Tooltip(tooltipTriggerEl)
        });
        
        // Show notification
        function showNotification(type, message) {
            const container = document.getElementById('notificationContainer');
            const notification = document.createElement('div');
            notification.className = `alert alert-${type} alert-dismissible fade show notification`;
            notification.setAttribute('role', 'alert');
            notification.innerHTML = `
                <div class="d-flex align-items-center">
                    <i class="fas ${type === 'success' ? 'fa-check-circle' : 'fa-exclamation-circle'} me-2"></i>
                    <div>${message}</div>
                    <button type="button" class="btn-close ms-3" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
            `;
            container.appendChild(notification);
            
            // Auto remove after 5 seconds
            setTimeout(() => {
                notification.remove();
            }, 5000);
        }
        
        // Show loading overlay
        function showLoading() {
            document.getElementById('loadingOverlay').classList.add('active');
        }
        
        // Hide loading overlay
        function hideLoading() {
            document.getElementById('loadingOverlay').classList.remove('active');
        }

       // Status Update Function with stage type support
        function updateStatus(ceId, type, newStatus) {
            // Frontend validation to enforce time completion
            if (newStatus === 'Completed') {
                let row = document.getElementById('row-' + ceId);
                if (row) {
                    let startTitle = type === 'ce' ? 'CE Start' : (type === 'ref' ? 'REF Start' : 'FQA Start');
                    let endTitle = type === 'ce' ? 'CE End' : (type === 'ref' ? 'REF End' : 'FQA End');
                    
                    let startInput = row.querySelector('input[title="' + startTitle + '"]');
                    let endInput = row.querySelector('input[title="' + endTitle + '"]');
                    
                    if (startInput && endInput) {
                        if (!startInput.value || !endInput.value) {
                            showNotification('danger', 'Please enter both Start Time and End Time before marking as Completed!');
                            setTimeout(() => { window.location.reload(); }, 1500); // reload to revert the dropdown change
                            return; 
                        }
                    }
                }
            }

            showLoading();
            fetch('ce_assign_tm.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: 'ajax=update_status&ce_id=' + ceId + '&type=' + encodeURIComponent(type) + '&status=' + encodeURIComponent(newStatus)
            })
            .then(response => response.json())
            .then(data => {
                hideLoading();
                if (data.success) {
                    showNotification('success', 'Status updated successfully!');
                    setTimeout(() => { window.location.reload(); }, 500);
                } else { 
                    showNotification('danger', 'Error updating status: ' + (data.message || '')); 
                    setTimeout(() => { window.location.reload(); }, 1500);
                }
            }).catch(error => { 
                hideLoading(); 
                console.error('Error:', error); 
                setTimeout(() => { window.location.reload(); }, 1500);
            });
        }

        // Inline Field Editor (For Counts and Time)
        function updateInlineField(ceId, field, value, element = null) {
            
            // --- BACKDATE PREVENTION LOGIC START ---
            if ((field.includes('start_time') || field.includes('end_time')) && value !== '') {
                const now = new Date();
                const currentHour = String(now.getHours()).padStart(2, '0');
                const currentMinute = String(now.getMinutes()).padStart(2, '0');
                const currentTime = currentHour + ':' + currentMinute;

                // Compare selected time with current time (Forces ONLY current time)
                if (value !== currentTime) {   // <---- INDHA LINE-A IPPADI MAATHUNGA
                    if (element) {
                        element.value = currentTime; 
                        value = currentTime;
                    } else {
                        return;
                    }
                }
            }
            // --- BACKDATE PREVENTION LOGIC END ---

            showLoading();
            fetch('ce_assign_tm.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: 'ajax=inline_edit&ce_id=' + ceId + '&field=' + encodeURIComponent(field) + '&value=' + encodeURIComponent(value)
            })
            .then(response => response.json())
            .then(data => {
                hideLoading();
                if (data.success) { showNotification('success', 'Value updated successfully!'); } 
                else { showNotification('danger', 'Update failed: ' + (data.message || 'Unknown error')); }
            }).catch(error => { hideLoading(); console.error('Error:', error); });
        }
        
        // Quick assign function
        function quickAssign(ceId, jobId, type, selectElement) {
            const selectedValue = selectElement.value;
            if (!selectedValue) return;
            
            const selectedOption = selectElement.options[selectElement.selectedIndex];
            const tmName = selectedOption.getAttribute('data-name') || selectedValue;
            
            let typeLabel = 'Content';
            if(type === 'images') typeLabel = 'Images';
            if(type === 'fqa') typeLabel = 'FQA';
            
            selectElement.setAttribute('data-previous', selectedValue);
            selectElement.disabled = true;
            
            fetch('ce_assign_tm.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: 'ajax=quick_assign&ce_id=' + ceId + '&job_id=' + jobId + '&type=' + type + '&assigned_to=' + encodeURIComponent(selectedValue)
            })
            .then(response => response.json())
            .then(data => {
                hideLoading();
                selectElement.disabled = false;
                if (data.success) {
                    showNotification('success', `${typeLabel} assigned to ${tmName} successfully!`);
                    document.getElementById('row-' + ceId).classList.add('assigned');
                    setTimeout(() => { window.location.reload(); }, 800); // Reload to show new badge
                } else {
                    showNotification('danger', 'Error: ' + data.message);
                    selectElement.value = selectElement.getAttribute('data-previous') || '';
                }
            })
            .catch(error => {
                hideLoading(); selectElement.disabled = false;
                showNotification('danger', 'Network error. Please try again.');
                selectElement.value = selectElement.getAttribute('data-previous') || '';
            });
        }
        
        // Show full assignment modal
        function showFullAssignment(ceId, jobId) {
            const modal = new bootstrap.Modal(document.getElementById('assignmentModal'));
            const modalBody = document.getElementById('modalBody');
            
            // Show loading
            modalBody.innerHTML = `
                <div class="text-center p-4">
                    <div class="spinner-border text-primary" role="status">
                        <span class="visually-hidden">Loading...</span>
                    </div>
                    <p class="mt-2 small">Loading assignment form...</p>
                </div>
            `;
            modal.show();
            
            // Load assignment form via AJAX
            fetch('get_assignment_form.php?ce_id=' + ceId + '&job_id=' + jobId)
                .then(response => response.text())
                .then(html => {
                    modalBody.innerHTML = html;
                })
                .catch(error => {
                    modalBody.innerHTML = '<div class="alert alert-danger">Error loading form. Please try again.</div>';
                });
        }

        
        // Auto-refresh every 5 minutes
          </script>

    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
    <script>
        $(document).ready(function() {
            // Activate Search + Dropdown
            $('#clientSearchDropdown').select2({
                width: '100%'
            });
        });
    </script>
</body>
</html>
