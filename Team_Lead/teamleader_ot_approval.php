<?php
ob_start();
// -------------------------------------------------------------
// FILE: teamleader_ot_approval.php
// PURPOSE: Team Leader interface to Approve/Reject OT claims
// -------------------------------------------------------------

require 'db.php';
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Check if user is logged in and is a Team Leader
$teamleader_username = $_SESSION['username'] ?? '';
$teamleader_id = $_SESSION['uid'] ?? 0;

// Get team leader's team
$tl_query = "SELECT team_id FROM employee_master WHERE employee_username = '$teamleader_username'";
$tl_result = $mysqli->query($tl_query);
$teamleader_data = $tl_result ? $tl_result->fetch_assoc() : null;
$teamleader_team_id = $teamleader_data['team_id'] ?? 0;

// --- 1. HANDLE APPROVAL/REJECTION ---
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    if (isset($_POST['action']) && isset($_POST['ot_id'])) {
        $ot_id = $_POST['ot_id'];
        $action = $_POST['action'];
        $admin_remarks = $mysqli->real_escape_string($_POST['admin_remarks'] ?? '');
        
        // Verify OT claim belongs to team member in same team
        $verify_query = "SELECT la.*, em.team_id 
                         FROM leave_applications la
                         JOIN employee_master em ON la.employee_username = em.employee_username
                         WHERE la.id = '$ot_id' 
                         AND em.team_id = '$teamleader_team_id' 
                         AND em.user_role_id IN (4, 11) 
                         AND la.request_type IN ('OT_Claim', 'Comp_Off_Leave', 'Permission')";
        $verify_result = $mysqli->query($verify_query);
        
        if ($verify_result && $verify_result->num_rows > 0) {
            $status = ($action == 'approve') ? 'Approved' : 'Rejected';
            
            $update_sql = "UPDATE leave_applications 
                          SET status = '$status', 
                              admin_remarks = '$admin_remarks',
                              approved_by = '$teamleader_username',
                              approved_at = NOW()
                          WHERE id = '$ot_id'";
            
            if ($mysqli->query($update_sql)) {
                $msg = "OT claim " . strtolower($status) . " successfully!";
                $msg_type = "success";
            } else {
                $msg = "Error: " . $mysqli->error;
                $msg_type = "danger";
            }
        } else {
            $msg = "You are not authorized to modify this OT claim!";
            $msg_type = "danger";
        }
    }
    
    // Handle bulk actions (only for pending claims)
    if (isset($_POST['bulk_action']) && isset($_POST['selected_claims'])) {
        $bulk_action = $_POST['bulk_action'];
        $selected_ids = is_array($_POST['selected_claims']) ? $_POST['selected_claims'] : explode(',', (string)$_POST['selected_claims']);
        $bulk_remarks = $mysqli->real_escape_string($_POST['bulk_remarks'] ?? '');
        
        $status = ($bulk_action == 'approve') ? 'Approved' : 'Rejected';
        $ids_str = implode(',', array_map('intval', array_filter($selected_ids)));
        
        if (!empty($ids_str)) {
            // Verify all selected claims belong to team members AND are pending
            $verify_query = "SELECT la.id 
                             FROM leave_applications la
                             JOIN employee_master em ON la.employee_username = em.employee_username
                             WHERE la.id IN ($ids_str) 
                             AND em.team_id = '$teamleader_team_id' 
                             AND em.user_role_id IN (4, 11) 
                             AND la.request_type IN ('OT_Claim', 'Comp_Off_Leave', 'Permission')
                             AND la.status = 'Pending'";
            $verify_result = $mysqli->query($verify_query);
            
            if ($verify_result && $verify_result->num_rows > 0) {
                $update_sql = "UPDATE leave_applications 
                              SET status = '$status', 
                                  admin_remarks = CONCAT(COALESCE(admin_remarks, ''), ' [Bulk: $bulk_remarks]'),
                                  approved_by = '$teamleader_username',
                                  approved_at = NOW()
                              WHERE id IN ($ids_str) 
                              AND status = 'Pending'";
                
                if ($mysqli->query($update_sql)) {
                    $msg = "Bulk action completed! " . $verify_result->num_rows . " claims " . strtolower($status);
                    $msg_type = "success";
                } else {
                    $msg = "Error in bulk update: " . $mysqli->error;
                    $msg_type = "danger";
                }
            } else {
                $msg = "No valid pending claims found for bulk action!";
                $msg_type = "warning";
            }
        }
    }
}

// --- 2. HANDLE EXCEL DOWNLOAD ---
if (isset($_GET['download']) && $_GET['download'] == 'excel') {
    // Fetch data for Excel
    $excel_query = "SELECT 
                    la.from_date as Date,
                    em.employee_name as 'Employee Name',
                    em.employee_username as 'Employee ID',
                    la.request_type as 'Type',
                    pm.process_name as Process,
                    la.article as Article,
                    la.count as Count,
                    la.start_time as 'Start Time',
                    la.end_time as 'End Time',
                    la.total_hours as 'Total Hours',
                    la.reason as Reason,
                    la.status as Status,
                    la.admin_remarks as Remarks,
                    la.approved_by as 'Approved By',
                    DATE_FORMAT(la.approved_at, '%d-%b-%Y %H:%i') as 'Approved Date'
                   FROM leave_applications la
                   JOIN employee_master em ON la.employee_username = em.employee_username
                   LEFT JOIN process_master pm ON la.process = pm.process_id
                   WHERE em.team_id = '$teamleader_team_id' 
                   AND em.user_role_id IN (4, 11) 
                   AND la.request_type IN ('OT_Claim', 'Comp_Off_Leave', 'Permission')
                   ORDER BY la.from_date DESC, la.id DESC";
    
    $excel_result = $mysqli->query($excel_query);
    
    // Generate Excel content without external libraries
    header('Content-Type: application/vnd.ms-excel');
    header('Content-Disposition: attachment; filename="OT_Claims_Team_' . $teamleader_team_id . '_' . date('Y-m-d') . '.xls"');
    
    echo '<table border="1">';
    echo '<tr><th colspan="15" style="background-color:#707981;color:white;font-size:16px;">OT CLAIMS REPORT - TEAM ' . $teamleader_team_id . '</th></tr>';
    echo '<tr><th colspan="15">Generated on: ' . date('d-M-Y H:i:s') . ' | Generated by: ' . $teamleader_username . '</th></tr>';
    echo '<tr>';
    
    // Headers
    if ($excel_result && $excel_result->num_rows > 0) {
        $row = $excel_result->fetch_assoc();
        foreach (array_keys($row) as $header) {
            echo '<th style="background-color:#f2f2f2;font-weight:bold;">' . $header . '</th>';
        }
        
        // Reset pointer
        $excel_result->data_seek(0);
        
        // Data rows
        while ($row = $excel_result->fetch_assoc()) {
            echo '<tr>';
            foreach ($row as $cell) {
                echo '<td>' . htmlspecialchars($cell ?? '') . '</td>';
            }
            echo '</tr>';
        }
    } else {
        echo '<tr><td colspan="15" style="text-align:center;">No data available</td></tr>';
    }
    
    echo '</table>';
    exit();
}

// --- 3. STATUS FILTER ---
$status_filter = $_GET['status'] ?? 'all'; // all, pending, approved, rejected
$status_condition = "";
if ($status_filter == 'pending') {
    $status_condition = "AND la.status = 'Pending'";
} elseif ($status_filter == 'approved') {
    $status_condition = "AND la.status = 'Approved'";
} elseif ($status_filter == 'rejected') {
    $status_condition = "AND la.status = 'Rejected'";
}

// Helpers
if (!function_exists('getEmpInitials')) {
    function getEmpInitials($name) {
        $words = preg_split('/[\s\.\-_]+/', trim((string)$name));
        $initials = '';
        foreach ($words as $w) {
            if (!empty($w)) {
                $initials .= strtoupper($w[0]);
            }
            if (strlen($initials) >= 2) break;
        }
        return $initials ?: 'EM';
    }
}

if (!function_exists('getEmpAvatarStyle')) {
    function getEmpAvatarStyle($string) {
        $palettes = [
            ['bg' => '#e0f2fe', 'border' => '#bae6fd', 'text' => '#0369a1'], // sky
            ['bg' => '#ecfdf5', 'border' => '#a7f3d0', 'text' => '#047857'], // emerald
            ['bg' => '#fef3c7', 'border' => '#fde68a', 'text' => '#b45309'], // amber
            ['bg' => '#ede9fe', 'border' => '#ddd6fe', 'text' => '#6d28d9'], // violet
            ['bg' => '#fce7f3', 'border' => '#fbcfe8', 'text' => '#be185d'], // pink
            ['bg' => '#e0e7ff', 'border' => '#c7d2fe', 'text' => '#4338ca'], // indigo
            ['bg' => '#f1f5f9', 'border' => '#e2e8f0', 'text' => '#334155'], // slate
        ];
        $hash = abs(crc32((string)$string));
        return $palettes[$hash % count($palettes)];
    }
}

include 'header.php';
?>

<style>
    /* =========================================================================
       SENIOR DEVELOPER STYLING - OT APPROVALS MANAGEMENT
       Preserving Exact Brand Palette: Solid Ash Grey #707981
       ========================================================================= */
    :root {
        --brand-ash: #707981;
        --brand-ash-dark: #5e666d;
        --brand-ash-light: #f1f5f9;
        --accent-blue: #0284c7;
        --accent-emerald: #10b981;
        --accent-amber: #f59e0b;
        --accent-rose: #ef4444;
    }

    .ot-main-container {
        padding: 90px 24px 40px 24px;
        max-width: 1560px;
        margin: 0 auto;
    }

    /* 1. Page Hero Banner */
    .page-hero-banner {
        background: #ffffff;
        border-radius: 14px;
        padding: 20px 24px;
        border: 1px solid #e2e8f0;
        box-shadow: 0 4px 16px rgba(112, 121, 129, 0.06);
    }

    .hero-icon-pill {
        width: 48px;
        height: 48px;
        border-radius: 12px;
        background: var(--brand-ash);
        color: #ffffff;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 1.35rem;
        box-shadow: 0 4px 12px rgba(112, 121, 129, 0.25);
        flex-shrink: 0;
    }

    .hero-title {
        font-size: 1.4rem;
        font-weight: 800;
        color: #1e293b;
        letter-spacing: -0.02em;
    }

    .hero-subtitle {
        font-size: 0.86rem;
        color: #64748b;
        font-weight: 500;
    }

    .team-badge-pill {
        background: #f8fafc;
        border: 1px solid #e2e8f0;
        color: #334155;
        font-size: 0.82rem;
        font-weight: 600;
        padding: 6px 14px;
        border-radius: 50rem;
        display: inline-flex;
        align-items: center;
    }

    /* 2. Senior Developer Stat Cards */
    .stat-card-modern {
        background: #ffffff;
        border-radius: 14px;
        padding: 20px;
        border: 1px solid #e2e8f0;
        box-shadow: 0 4px 14px rgba(0, 0, 0, 0.03);
        transition: transform 0.2s ease, box-shadow 0.2s ease;
        position: relative;
        overflow: hidden;
    }

    .stat-card-modern:hover {
        transform: translateY(-2px);
        box-shadow: 0 8px 20px rgba(0, 0, 0, 0.06);
    }

    .stat-card-modern::before {
        content: '';
        position: absolute;
        top: 0;
        left: 0;
        width: 4px;
        height: 100%;
    }

    .stat-card-modern.stat-pending::before { background-color: var(--accent-amber); }
    .stat-card-modern.stat-approved::before { background-color: var(--accent-emerald); }
    .stat-card-modern.stat-rejected::before { background-color: var(--accent-rose); }
    .stat-card-modern.stat-hours::before { background-color: var(--accent-blue); }

    .stat-label {
        font-size: 0.72rem;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: 0.6px;
        color: #64748b;
        margin-bottom: 6px;
        display: block;
    }

    .stat-number {
        font-size: 2rem;
        font-weight: 800;
        color: #1e293b;
        line-height: 1.1;
        letter-spacing: -0.02em;
    }

    .stat-subtext {
        font-size: 0.76rem;
        font-weight: 600;
        margin-top: 6px;
        display: inline-flex;
        align-items: center;
    }

    .stat-icon-wrapper {
        width: 46px;
        height: 46px;
        border-radius: 12px;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 1.25rem;
        flex-shrink: 0;
    }

    /* 3. Modern Filter Tabs */
    .filter-tab-container {
        background: #ffffff;
        border-radius: 12px;
        border: 1px solid #e2e8f0;
        padding: 6px;
        box-shadow: 0 2px 8px rgba(0, 0, 0, 0.03);
    }

    .tab-pill-btn {
        display: inline-flex;
        align-items: center;
        gap: 8px;
        padding: 8px 16px;
        border-radius: 8px;
        font-size: 0.84rem;
        font-weight: 600;
        color: #475569;
        text-decoration: none;
        transition: all 0.2s ease;
    }

    .tab-pill-btn:hover {
        background-color: #f1f5f9;
        color: #0f172a;
    }

    .tab-pill-btn.active {
        background-color: var(--brand-ash);
        color: #ffffff;
        box-shadow: 0 2px 8px rgba(112, 121, 129, 0.3);
    }

    .tab-pill-badge {
        font-size: 0.72rem;
        font-weight: 700;
        padding: 2px 8px;
        border-radius: 50rem;
        background-color: #e2e8f0;
        color: #334155;
    }

    .tab-pill-btn.active .tab-pill-badge {
        background-color: #ffffff;
        color: #1e293b;
    }

    /* 4. Bulk Action Floating Bar */
    .bulk-action-bar {
        background: #707981;
        border-radius: 12px;
        color: #ffffff;
        box-shadow: 0 8px 24px rgba(112, 121, 129, 0.28);
        border: 1px solid rgba(255, 255, 255, 0.15);
    }

    .bulk-icon-box {
        width: 36px;
        height: 36px;
        border-radius: 8px;
        background: rgba(255, 255, 255, 0.18);
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 1rem;
    }

    /* 5. Master Table Card */
    .card-modern {
        background: #ffffff;
        border-radius: 14px;
        border: 1px solid #e2e8f0;
        box-shadow: 0 4px 18px rgba(112, 121, 129, 0.05);
        overflow: hidden;
    }

    .card-header-ash {
        background-color: var(--brand-ash) !important;
        background: var(--brand-ash) !important;
        color: #ffffff !important;
        padding: 14px 20px;
        border-bottom: 1px solid rgba(255, 255, 255, 0.2) !important;
    }

    .header-icon-box {
        width: 34px;
        height: 34px;
        border-radius: 8px;
        background: rgba(255, 255, 255, 0.15);
        border: 1px solid rgba(255, 255, 255, 0.2);
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 0.95rem;
        color: #ffffff;
        flex-shrink: 0;
    }

    .target-count-pill {
        background-color: #ffffff !important;
        color: #1e293b !important;
        font-weight: 700 !important;
        font-size: 0.8rem !important;
        padding: 5px 14px !important;
        border-radius: 50rem !important;
        border: 1px solid rgba(226, 232, 240, 0.95) !important;
        box-shadow: 0 2px 6px rgba(0, 0, 0, 0.08) !important;
        display: inline-flex !important;
        align-items: center !important;
        gap: 6px !important;
    }

    /* Table Structure */
    .table-modern-ot {
        width: 100%;
        margin-bottom: 0;
        border-collapse: separate;
        border-spacing: 0;
    }

    .table-modern-ot thead th {
        background-color: var(--brand-ash) !important;
        background: var(--brand-ash) !important;
        color: #ffffff !important;
        font-size: 0.78rem;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: 0.5px;
        padding: 11px 12px;
        border: none;
        vertical-align: middle;
        white-space: nowrap;
    }

    .table-modern-ot tbody td {
        padding: 12px 14px;
        vertical-align: middle;
        border-top: 1px solid #f1f5f9;
        font-size: 0.86rem;
    }

    .table-modern-ot tbody tr:hover {
        background-color: #f8fafc;
    }

    .row-approved {
        background-color: #fcfdfc;
    }

    .row-rejected {
        background-color: #fefcfc;
    }

    /* Avatars */
    .emp-avatar-circle {
        width: 32px;
        height: 32px;
        border-radius: 50%;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        font-size: 0.72rem;
        font-weight: 700;
        letter-spacing: 0.5px;
        flex-shrink: 0;
    }

    /* Modern Type Badges */
    .badge-type-pill {
        font-size: 0.73rem;
        font-weight: 600;
        padding: 4px 8px;
        border-radius: 6px;
        display: inline-flex;
        align-items: center;
        white-space: nowrap;
    }

    .badge-ot {
        background-color: #ede9fe;
        color: #6366f1;
        border: 1px solid #ddd6fe;
    }

    .badge-comp {
        background-color: #ecfdf5;
        color: #047857;
        border: 1px solid #a7f3d0;
    }

    .badge-permission {
        background-color: #e0f2fe;
        color: #0369a1;
        border: 1px solid #bae6fd;
    }

    /* Modern Status Badges */
    .badge-status-pill {
        font-size: 0.75rem;
        font-weight: 700;
        padding: 4px 10px;
        border-radius: 50rem;
        display: inline-flex;
        align-items: center;
        gap: 4px;
        white-space: nowrap;
    }

    .status-Pending {
        background-color: #fffbeb;
        color: #b45309;
        border: 1px solid #fde68a;
    }

    .status-Approved {
        background-color: #ecfdf5;
        color: #047857;
        border: 1px solid #a7f3d0;
    }

    .status-Rejected {
        background-color: #fef2f2;
        color: #b91c1c;
        border: 1px solid #fecaca;
    }

    /* Remarks Card */
    .remarks-readonly-card {
        background-color: #f8fafc;
        border: 1px solid #e2e8f0;
        border-radius: 6px;
        padding: 6px 10px;
        font-size: 0.8rem;
        line-height: 1.35;
        word-break: break-word;
        max-width: 220px;
    }

    /* Action Buttons */
    .btn-action-approve {
        background-color: #10b981;
        color: #ffffff;
        border: none;
        font-size: 0.76rem;
        font-weight: 600;
        padding: 4px 10px;
        border-radius: 6px;
        transition: all 0.2s ease;
        display: inline-flex;
        align-items: center;
        gap: 4px;
    }

    .btn-action-approve:hover {
        background-color: #059669;
        color: #ffffff;
    }

    .btn-action-reject {
        background-color: #ef4444;
        color: #ffffff;
        border: none;
        font-size: 0.76rem;
        font-weight: 600;
        padding: 4px 10px;
        border-radius: 6px;
        transition: all 0.2s ease;
        display: inline-flex;
        align-items: center;
        gap: 4px;
    }

    .btn-action-reject:hover {
        background-color: #dc2626;
        color: #ffffff;
    }

    /* Empty State Circle */
    .empty-icon-circle {
        width: 68px;
        height: 68px;
        border-radius: 50%;
        background-color: #f1f5f9;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 1.85rem;
        color: #94a3b8;
    }
</style>

<div class="ot-main-container">

    <!-- 1. Page Hero Banner -->
    <div class="page-hero-banner mb-4">
        <div class="d-flex flex-wrap justify-content-between align-items-center gap-3">
            <div class="d-flex align-items-center gap-3">
                <div class="hero-icon-pill">
                    <i class="fas fa-business-time"></i>
                </div>
                <div>
                    <h3 class="hero-title mb-1">OT Approvals</h3>
                    <p class="hero-subtitle mb-0">Review and manage overtime claims, comp-off requests, and permissions from your team members.</p>
                </div>
            </div>
            <div class="d-flex align-items-center gap-2">
                <div class="team-badge-pill">
                    <i class="fas fa-users-gear text-primary me-1"></i>
                    <span>Team <strong><?= htmlspecialchars((string)$teamleader_team_id) ?></strong></span>
                </div>
                <!-- Excel Download Button (Functionality Preserved) -->
                <!-- <a href="?download=excel" class="btn btn-outline-secondary btn-sm rounded-pill px-3">
                    <i class="fa fa-file-excel-o"></i> Download Excel
                </a> -->
            </div>
        </div>
    </div>

    <!-- Feedback Message Alert -->
    <?php if(isset($msg)): ?>
        <div class="alert alert-<?= $msg_type ?> alert-dismissible fade show rounded-3 shadow-sm" role="alert">
            <i class="fas <?= $msg_type === 'success' ? 'fa-check-circle' : ($msg_type === 'danger' ? 'fa-exclamation-circle' : 'fa-info-circle') ?> me-2"></i>
            <?= $msg ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <!-- 2. Team Statistics Cards -->
    <div class="row g-3 mb-4">
        <?php
        // Get team statistics (Functionality & Query 100% Preserved)
        $stats_query = "SELECT 
                        COUNT(CASE WHEN la.status = 'Pending' THEN 1 END) as pending_count,
                        COUNT(CASE WHEN la.status = 'Approved' THEN 1 END) as approved_count,
                        COUNT(CASE WHEN la.status = 'Rejected' THEN 1 END) as rejected_count,
                        SUM(CASE WHEN la.status = 'Approved' THEN la.total_hours ELSE 0 END) as total_approved_hours,
                        COUNT(DISTINCT la.employee_username) as team_members
                       FROM leave_applications la
                       JOIN employee_master em ON la.employee_username = em.employee_username
                       WHERE em.team_id = '$teamleader_team_id' 
                       AND em.user_role_id IN (4, 11) 
                       AND la.request_type IN ('OT_Claim', 'Comp_Off_Leave', 'Permission')";
        
        $stats_result = $mysqli->query($stats_query);
        $stats = $stats_result ? $stats_result->fetch_assoc() : [];
        ?>
        
        <!-- Pending Claims -->
        <div class="col-12 col-sm-6 col-xl-3">
            <div class="stat-card-modern stat-pending">
                <div class="d-flex align-items-center justify-content-between">
                    <div>
                        <span class="stat-label">Pending Claims</span>
                        <div class="stat-number"><?= number_format($stats['pending_count'] ?? 0) ?></div>
                        <span class="stat-subtext text-warning"><i class="far fa-clock me-1"></i>Awaiting Review</span>
                    </div>
                    <div class="stat-icon-wrapper bg-warning-subtle text-warning-emphasis">
                        <i class="fas fa-hourglass-half"></i>
                    </div>
                </div>
            </div>
        </div>

        <!-- Approved Claims -->
        <div class="col-12 col-sm-6 col-xl-3">
            <div class="stat-card-modern stat-approved">
                <div class="d-flex align-items-center justify-content-between">
                    <div>
                        <span class="stat-label">Approved Claims</span>
                        <div class="stat-number"><?= number_format($stats['approved_count'] ?? 0) ?></div>
                        <span class="stat-subtext text-success"><i class="far fa-check-circle me-1"></i>Approved to Date</span>
                    </div>
                    <div class="stat-icon-wrapper bg-success-subtle text-success">
                        <i class="fas fa-circle-check"></i>
                    </div>
                </div>
            </div>
        </div>

        <!-- Rejected Claims -->
        <div class="col-12 col-sm-6 col-xl-3">
            <div class="stat-card-modern stat-rejected">
                <div class="d-flex align-items-center justify-content-between">
                    <div>
                        <span class="stat-label">Rejected Claims</span>
                        <div class="stat-number"><?= number_format($stats['rejected_count'] ?? 0) ?></div>
                        <span class="stat-subtext text-danger"><i class="far fa-times-circle me-1"></i>Declined Requests</span>
                    </div>
                    <div class="stat-icon-wrapper bg-danger-subtle text-danger">
                        <i class="fas fa-circle-xmark"></i>
                    </div>
                </div>
            </div>
        </div>

        <!-- Total Approved Hours -->
        <div class="col-12 col-sm-6 col-xl-3">
            <div class="stat-card-modern stat-hours">
                <div class="d-flex align-items-center justify-content-between">
                    <div>
                        <span class="stat-label">Total Approved Hours</span>
                        <div class="stat-number">
                            <?= round((float)($stats['total_approved_hours'] ?? 0), 1) ?>
                            <span style="font-size: 0.9rem; font-weight: 600; color: #64748b;">hrs</span>
                        </div>
                        <span class="stat-subtext text-primary"><i class="fas fa-calculator me-1"></i>Recorded Time</span>
                    </div>
                    <div class="stat-icon-wrapper bg-primary-subtle text-primary">
                        <i class="fas fa-clock"></i>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- 3. Status Filter Tabs -->
    <div class="filter-tab-container mb-4">
        <div class="d-flex flex-wrap align-items-center justify-content-between gap-2">
            <div class="d-flex flex-wrap gap-2">
                <a class="tab-pill-btn <?= $status_filter == 'all' ? 'active' : '' ?>" href="?status=all">
                    <i class="fas fa-list"></i>
                    <span>All Claims</span>
                    <span class="tab-pill-badge"><?= (($stats['pending_count'] ?? 0) + ($stats['approved_count'] ?? 0) + ($stats['rejected_count'] ?? 0)) ?></span>
                </a>
                <a class="tab-pill-btn <?= $status_filter == 'pending' ? 'active' : '' ?>" href="?status=pending">
                    <i class="fas fa-hourglass-half"></i>
                    <span>Pending</span>
                    <span class="tab-pill-badge"><?= $stats['pending_count'] ?? 0 ?></span>
                </a>
                <a class="tab-pill-btn <?= $status_filter == 'approved' ? 'active' : '' ?>" href="?status=approved">
                    <i class="fas fa-check-circle"></i>
                    <span>Approved</span>
                    <span class="tab-pill-badge"><?= $stats['approved_count'] ?? 0 ?></span>
                </a>
                <a class="tab-pill-btn <?= $status_filter == 'rejected' ? 'active' : '' ?>" href="?status=rejected">
                    <i class="fas fa-times-circle"></i>
                    <span>Rejected</span>
                    <span class="tab-pill-badge"><?= $stats['rejected_count'] ?? 0 ?></span>
                </a>
            </div>
            <div class="text-muted small px-2">
                <i class="fas fa-filter me-1 text-primary"></i> Current Filter: <strong class="text-dark"><?= ucfirst($status_filter) ?></strong>
            </div>
        </div>
    </div>

    <!-- 4. Bulk Action Panel (Shown dynamically for pending claims) -->
    <?php if ($status_filter == 'all' || $status_filter == 'pending'): ?>
    <div class="bulk-action-bar mb-4" id="bulkPanel" style="display: none;">
        <form method="POST" action="" id="bulkForm" class="d-flex flex-wrap align-items-center justify-content-between gap-3 p-3">
            <div class="d-flex align-items-center gap-2">
                <span class="bulk-icon-box"><i class="fas fa-layer-group"></i></span>
                <div>
                    <div class="fw-bold fs-6">Bulk Review on Selected Claims</div>
                    <small class="text-white-50"><span id="selectedCount" class="badge bg-white text-dark fw-bold px-2 py-1 me-1">0</span> claim(s) selected</small>
                </div>
            </div>
            <div class="d-flex flex-wrap align-items-center gap-2 flex-grow-1 justify-content-md-end">
                <div style="min-width: 175px;">
                    <select name="bulk_action" class="form-select form-select-sm" required>
                        <option value="" selected disabled>-- Select Action --</option>
                        <option value="approve">Approve Selected</option>
                        <option value="reject">Reject Selected</option>
                    </select>
                </div>
                <div style="min-width: 240px;" class="flex-grow-1 flex-md-grow-0">
                    <input type="text" name="bulk_remarks" class="form-control form-control-sm" placeholder="Enter remarks for bulk action" required>
                </div>
                <button type="submit" class="btn btn-sm btn-light fw-bold px-3">
                    <i class="fas fa-bolt text-primary me-1"></i> Apply
                </button>
                <button type="button" class="btn btn-sm btn-outline-light px-3" onclick="clearSelection()">
                    Cancel
                </button>
            </div>
            <input type="hidden" name="selected_claims[]" id="selectedClaims">
        </form>
    </div>
    <?php endif; ?>

    <!-- 5. OT CLAIMS MASTER TABLE -->
    <div class="card card-modern border-0 shadow-sm rounded-3">
        <div class="card-header card-header-ash d-flex justify-content-between align-items-center py-3 px-4">
            <div class="d-flex align-items-center gap-2">
                <div class="header-icon-box">
                    <i class="fas fa-user-clock"></i>
                </div>
                <h5 class="mb-0 fw-bold text-white">
                    OT Approvals &bull; Team <?= htmlspecialchars((string)$teamleader_team_id) ?>
                </h5>
            </div>
            <div>
                <span class="target-count-pill">
                    <i class="fas fa-eye text-primary"></i>
                    Showing: <?= ucfirst($status_filter) ?>
                </span>
            </div>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0 table-modern-ot">
                    <thead>
                        <tr>
                            <?php if ($status_filter == 'all' || $status_filter == 'pending'): ?>
                            <th class="select-checkbox text-center" style="width: 45px;">
                                <input type="checkbox" class="form-check-input" id="selectAll" onclick="toggleAllSelection()" title="Select All">
                            </th>
                            <?php else: ?>
                            <th class="select-checkbox text-center" style="width: 45px;"></th>
                            <?php endif; ?>
                            <th style="min-width: 105px;">Date</th>
                            <th style="min-width: 175px;">Employee</th>
                            <th style="min-width: 120px;">Type</th>
                            <th style="min-width: 115px;">Process</th>
                            <th style="min-width: 100px;">Article</th>
                            <th style="min-width: 160px;">Time & Duration</th>
                            <th style="min-width: 85px;" class="text-center">Count</th>
                            <th style="min-width: 170px;">Reason</th>
                            <th style="min-width: 115px;" class="text-center">Status</th>
                            <th style="min-width: 190px;">Remarks</th>
                            <th style="min-width: 155px;" class="text-center">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        // Fetch OT claims from same team with status filter (Query 100% Preserved)
                        $query = "SELECT 
                                la.*,
                                em.employee_name,
                                em.process as emp_process,
                                pm.process_name
                               FROM leave_applications la
                               JOIN employee_master em ON la.employee_username = em.employee_username
                               LEFT JOIN process_master pm ON la.process = pm.process_id
                               WHERE em.team_id = '$teamleader_team_id' 
                               AND em.user_role_id IN (4, 11) 
                               AND la.request_type IN ('OT_Claim', 'Comp_Off_Leave', 'Permission')
                               $status_condition
                               ORDER BY la.from_date DESC, la.id DESC";
                        
                        $result = $mysqli->query($query);

                        if ($result && $result->num_rows > 0) {
                            while ($row = $result->fetch_assoc()) {
                                $type_label = str_replace('_', ' ', (string)($row['request_type'] ?? ''));
                                $ot_date = !empty($row['from_date']) ? date('d-M-Y', strtotime($row['from_date'])) : '—';
                                $process_display = !empty($row['process_name']) ? $row['process_name'] : ($row['emp_process'] ?? '');
                                $is_pending = ($row['status'] == 'Pending');
                                $row_class = $is_pending ? '' : ($row['status'] == 'Approved' ? 'row-approved' : 'row-rejected');
                                $avatarStyle = getEmpAvatarStyle($row['employee_username'] ?? $row['employee_name'] ?? '');
                                $initials = getEmpInitials($row['employee_name'] ?? $row['employee_username'] ?? '');
                                ?>
                                <tr id="row-<?= $row['id'] ?>" class="<?= $row_class ?>">
                                    <?php if ($status_filter == 'all' || $status_filter == 'pending'): ?>
                                    <td class="select-checkbox text-center">
                                        <?php if ($is_pending): ?>
                                            <input type="checkbox" class="claim-checkbox form-check-input" value="<?= $row['id'] ?>" onchange="updateSelection()">
                                        <?php else: ?>
                                            <span class="text-muted"><i class="fas fa-minus small opacity-25"></i></span>
                                        <?php endif; ?>
                                    </td>
                                    <?php else: ?>
                                    <td class="select-checkbox text-center">
                                        <span class="text-muted"><i class="fas fa-minus small opacity-25"></i></span>
                                    </td>
                                    <?php endif; ?>
                                    
                                    <td class="fw-bold text-dark text-nowrap">
                                        <i class="far fa-calendar-alt text-muted me-1 small"></i><?= $ot_date ?>
                                    </td>
                                    <td>
                                        <div class="d-flex align-items-center gap-2">
                                            <div class="emp-avatar-circle" style="background-color: <?= $avatarStyle['bg'] ?>; color: <?= $avatarStyle['text'] ?>; border: 1px solid <?= $avatarStyle['border'] ?>;">
                                                <?= htmlspecialchars($initials) ?>
                                            </div>
                                            <div>
                                                <div class="fw-bold text-dark text-truncate" style="max-width: 160px;"><?= htmlspecialchars((string)($row['employee_name'] ?? '')) ?></div>
                                                <span class="badge bg-light text-muted border font-monospace py-0 px-1" style="font-size: 0.72rem;">
                                                    <?= htmlspecialchars((string)($row['employee_username'] ?? '')) ?>
                                                </span>
                                            </div>
                                        </div>
                                    </td>
                                    <td>
                                        <?php if ($row['request_type'] == 'OT_Claim'): ?>
                                            <span class="badge-type-pill badge-ot">
                                                <i class="fas fa-clock me-1"></i><?= $type_label ?>
                                            </span>
                                        <?php elseif ($row['request_type'] == 'Permission'): ?>
                                            <span class="badge-type-pill badge-permission">
                                                <i class="fas fa-door-open me-1"></i><?= $type_label ?>
                                            </span>
                                        <?php else: ?>
                                            <span class="badge-type-pill badge-comp">
                                                <i class="fas fa-calendar-check me-1"></i><?= $type_label ?>
                                            </span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <span class="badge bg-light text-secondary border font-monospace">
                                            <?= htmlspecialchars((string)($process_display ?: 'General')) ?>
                                        </span>
                                    </td>
                                    <td>
                                        <?php if (!empty($row['article'])): ?>
                                            <span class="badge bg-success-subtle text-success border border-success-subtle">
                                                <?= htmlspecialchars((string)$row['article']) ?>
                                            </span>
                                        <?php else: ?>
                                            <span class="text-muted small">—</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <div class="small fw-semibold text-dark text-nowrap">
                                            <?php if (!empty($row['start_time']) && !empty($row['end_time'])): ?>
                                                <?= date('g:i A', strtotime($row['start_time'])) ?> &rarr; <?= date('g:i A', strtotime($row['end_time'])) ?>
                                            <?php else: ?>
                                                <span class="text-muted">—</span>
                                            <?php endif; ?>
                                        </div>
                                        <?php if (!empty($row['total_hours'])): ?>
                                            <span class="badge bg-warning-subtle text-warning-emphasis border border-warning-subtle py-0 px-1 mt-1" style="font-size: 0.74rem;">
                                                <i class="fas fa-stopwatch me-1"></i><?= $row['total_hours'] ?> hrs
                                            </span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="fw-bold text-center">
                                        <?= !empty($row['count']) ? number_format((float)$row['count']) : '<span class="text-muted">—</span>' ?>
                                    </td>
                                    <td class="small text-secondary" style="max-width: 200px;">
                                        <div class="text-truncate" title="<?= htmlspecialchars((string)($row['reason'] ?? '')) ?>">
                                            <?= htmlspecialchars((string)($row['reason'] ?? '—')) ?>
                                        </div>
                                    </td>
                                    <td class="text-center">
                                        <span class="badge-status-pill status-<?= htmlspecialchars((string)$row['status']) ?>">
                                            <?php if ($row['status'] == 'Approved'): ?>
                                                <i class="fas fa-check-circle"></i>
                                            <?php elseif ($row['status'] == 'Rejected'): ?>
                                                <i class="fas fa-times-circle"></i>
                                            <?php else: ?>
                                                <i class="far fa-clock"></i>
                                            <?php endif; ?>
                                            <?= htmlspecialchars((string)$row['status']) ?>
                                        </span>
                                    </td>
                                    <td>
                                        <?php if ($is_pending): ?>
                                            <input type="text" 
                                                   class="form-control form-control-sm remarks-input shadow-none" 
                                                   id="remarks-<?= $row['id'] ?>" 
                                                   placeholder="Enter review remarks..."
                                                   style="font-size: 0.82rem; border-radius: 6px;">
                                        <?php else: ?>
                                            <div class="remarks-readonly-card">
                                                <div class="text-dark"><?= !empty($row['admin_remarks']) ? htmlspecialchars((string)$row['admin_remarks']) : '<span class="text-muted">No remarks</span>' ?></div>
                                                <?php if (!empty($row['approved_by'])): ?>
                                                    <div class="text-muted mt-1 pt-1 border-top" style="font-size: 0.72rem;">
                                                        <span><i class="far fa-user me-1"></i><?= htmlspecialchars((string)$row['approved_by']) ?></span>
                                                        <?php if (!empty($row['approved_at'])): ?>
                                                            <span class="ms-1 text-nowrap">&bull; <?= date('d-M-y H:i', strtotime($row['approved_at'])) ?></span>
                                                        <?php endif; ?>
                                                    </div>
                                                <?php endif; ?>
                                            </div>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-center">
                                        <?php if ($is_pending): ?>
                                            <div class="d-inline-flex gap-1">
                                                <button type="button" class="btn btn-action-approve" onclick="approveClaim(<?= $row['id'] ?>)" title="Approve Claim">
                                                    <i class="fas fa-check"></i> Approve
                                                </button>
                                                <button type="button" class="btn btn-action-reject" onclick="rejectClaim(<?= $row['id'] ?>)" title="Reject Claim">
                                                    <i class="fas fa-times"></i> Reject
                                                </button>
                                            </div>
                                        <?php else: ?>
                                            <span class="badge bg-light text-secondary border px-2 py-1" style="font-size: 0.76rem;">
                                                <i class="fas fa-lock me-1"></i> <?= $row['status'] == 'Approved' ? 'Approved' : 'Rejected' ?>
                                            </span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <?php
                            }
                        } else {
                            $colspan = ($status_filter == 'all' || $status_filter == 'pending') ? 12 : 11;
                            ?>
                            <tr>
                                <td colspan="<?= $colspan ?>" class="text-center py-5">
                                    <div class="py-4">
                                        <div class="empty-icon-circle mx-auto mb-3">
                                            <i class="far fa-folder-open"></i>
                                        </div>
                                        <h6 class="fw-bold text-dark mb-1">No OT Claims Found</h6>
                                        <p class="text-muted small mb-0">There are currently no <?= $status_filter !== 'all' ? htmlspecialchars($status_filter) : '' ?> overtime or permission requests for Team <?= htmlspecialchars((string)$teamleader_team_id) ?>.</p>
                                    </div>
                                </td>
                            </tr>
                            <?php
                        }
                        ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<!-- Hidden form for single actions (Functionality 100% Preserved) -->
<form method="POST" action="" id="actionForm" style="display: none;">
    <input type="hidden" name="ot_id" id="otId">
    <input type="hidden" name="action" id="actionType">
    <input type="hidden" name="admin_remarks" id="actionRemarks">
</form>

<!-- Scripts -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
    // Single claim approval/rejection (only for pending claims - 100% Preserved)
    function approveClaim(otId) {
        const remarksInput = document.getElementById('remarks-' + otId);
        const remarks = remarksInput ? remarksInput.value : '';
        if (confirm('Are you sure you want to approve this OT claim?')) {
            document.getElementById('otId').value = otId;
            document.getElementById('actionType').value = 'approve';
            document.getElementById('actionRemarks').value = remarks;
            document.getElementById('actionForm').submit();
        }
    }
    
    function rejectClaim(otId) {
        const remarksInput = document.getElementById('remarks-' + otId);
        const remarks = remarksInput ? remarksInput.value.trim() : '';
        if (!remarks) {
            alert('Please enter remarks for rejection');
            if (remarksInput) remarksInput.focus();
            return;
        }
        if (confirm('Are you sure you want to reject this OT claim?')) {
            document.getElementById('otId').value = otId;
            document.getElementById('actionType').value = 'reject';
            document.getElementById('actionRemarks').value = remarks;
            document.getElementById('actionForm').submit();
        }
    }
    
    // Bulk selection functions (only for pending claims - 100% Preserved)
    let selectedClaims = [];
    
    function updateSelection() {
        selectedClaims = [];
        const checkboxes = document.querySelectorAll('.claim-checkbox:checked');
        
        checkboxes.forEach(checkbox => {
            selectedClaims.push(checkbox.value);
        });
        
        const countEl = document.getElementById('selectedCount');
        if (countEl) countEl.textContent = selectedClaims.length;
        
        const claimsEl = document.getElementById('selectedClaims');
        if (claimsEl) claimsEl.value = selectedClaims.join(',');
        
        // Show/hide bulk panel
        const bulkPanel = document.getElementById('bulkPanel');
        if (bulkPanel) {
            bulkPanel.style.display = selectedClaims.length > 0 ? 'block' : 'none';
        }
        
        // Update select all checkbox
        const allCheckboxes = document.querySelectorAll('.claim-checkbox');
        const selectAll = document.getElementById('selectAll');
        if (selectAll && allCheckboxes.length > 0) {
            selectAll.checked = (selectedClaims.length === allCheckboxes.length);
        }
    }
    
    function toggleAllSelection() {
        const selectAll = document.getElementById('selectAll');
        if (!selectAll) return;
        
        const checkboxes = document.querySelectorAll('.claim-checkbox');
        checkboxes.forEach(checkbox => {
            checkbox.checked = selectAll.checked;
        });
        
        updateSelection();
    }
    
    function clearSelection() {
        const checkboxes = document.querySelectorAll('.claim-checkbox');
        checkboxes.forEach(checkbox => {
            checkbox.checked = false;
        });
        
        const selectAll = document.getElementById('selectAll');
        if (selectAll) {
            selectAll.checked = false;
        }
        
        selectedClaims = [];
        const bulkPanel = document.getElementById('bulkPanel');
        if (bulkPanel) {
            bulkPanel.style.display = 'none';
        }
        
        // Reset bulk form
        const bulkForm = document.getElementById('bulkForm');
        if (bulkForm) {
            bulkForm.reset();
        }
    }
    
    // Auto-dismiss alerts
    document.addEventListener('DOMContentLoaded', function() {
        setTimeout(() => {
            const alerts = document.querySelectorAll('.alert');
            alerts.forEach(alert => {
                if (alert.isConnected) {
                    const bsAlert = new bootstrap.Alert(alert);
                    bsAlert.close();
                }
            });
        }, 5000);
    });
</script>
</body>
</html>
