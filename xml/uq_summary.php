<?php
// Start session if not already active
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/db.php';

// Safe escaping helper
if (!function_exists('h')) {
    function h($s) {
        return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
    }
}

// Number cleaner helper
function cleanNum($val) {
    if (empty($val)) return 0;
    $s = trim((string)$val);
    if ($s === '-' || strtolower($s) === 'n/a' || strtolower($s) === 'na' || strtolower($s) === 'pending') {
        return 0;
    }
    $s = str_replace([',', ' '], '', $s);
    return is_numeric($s) ? (float)$s : 0;
}

function fmtVal($num) {
    if ($num <= 0) return '<span class="text-muted opacity-50">—</span>';
    return number_format($num);
}

// Pending helper: if activity happened and completed (pending == 0), show 0 instead of —
function fmtPending($pending, $has_activity = false) {
    if ($pending == 0) {
        if ($has_activity) {
            return '<span class="text-success fw-bold">0</span>';
        } else {
            return '<span class="text-muted opacity-50">—</span>';
        }
    } elseif ($pending > 0) {
        return '<span class="text-danger fw-bold">' . number_format($pending) . '</span>';
    } else {
        return '<span class="text-dark fw-bold">' . number_format($pending) . '</span>';
    }
}

// Fiscal / Operational Week Calculator (April 1st based, matching Meeting Report / Weekly Report)
if (!function_exists('calculateFiscalWeekNumber')) {
    function calculateFiscalWeekNumber($dateStr) {
        if (!$dateStr) return 1;
        $d = new DateTime($dateStr);
        $year = (int)$d->format('Y');
        $month = (int)$d->format('m');
        $fiscalStart = ($month < 4) ? new DateTime(($year - 1) . '-04-01') : new DateTime($year . '-04-01');
        if ($d < $fiscalStart) return 1;
        $cur = clone $fiscalStart;
        $sundays = 0;
        while ($cur < $d) {
            if ($cur->format('w') == 0) $sundays++;
            $cur->modify('+1 day');
        }
        return (1 + $sundays);
    }
}

// Robust Date Parser helper for database string formats
function parseToDateStr($date_val) {
    if (empty($date_val)) return '';
    $s = trim((string)$date_val);
    if ($s === '0000-00-00' || $s === '0000-00-00 00:00:00' || $s === '—' || $s === '-' || strtolower($s) === 'null') {
        return '';
    }

    $formats = [
        'Y-m-d H:i:s',
        'Y-m-d',
        'd-M-Y H:i:s',
        'd-M-Y H:i',
        'd-M-Y',
        'd-m-Y H:i:s',
        'd-m-Y H:i',
        'd-m-Y',
        'Y/m/d H:i:s',
        'Y/m/d',
        'm/d/Y'
    ];

    foreach ($formats as $fmt) {
        $d = DateTime::createFromFormat($fmt, $s);
        if ($d !== false) {
            return $d->format('Y-m-d');
        }
    }

    $ts = strtotime($s);
    if ($ts !== false && $ts > 0) {
        return date('Y-m-d', $ts);
    }

    return '';
}

// Subtask completion & date resolution helper
function getTaskCompletionInfo($user_val, $qty, $job_status, $qc_user, $job_desp_date, $job_rec_date, $fallback_year) {
    if ($qty <= 0) {
        return ['is_completed' => false, 'date' => ''];
    }

    $u = trim((string)$user_val);
    $qc = trim((string)$qc_user);
    $status = strtolower(trim((string)$job_status));

    // Check 1: Subtask is completed with checkmark or done
    // e.g. "T. Deepa/26-11/10 ✓", "M. Akila/23-11/10 ✓", "T. Deepa # ✓", "done", "completed"
    $is_subtask_done = (strpos($u, '✓') !== false || stripos($u, 'done') !== false || stripos($u, 'completed') !== false);

    // Check 2: QC is done (whole job approved)
    $is_qc_done = (strpos($qc, '✓') !== false || stripos($qc, 'done') !== false || stripos($qc, 'completed') !== false);

    // Check 3: Explicitly Despatched (not 'Assign', not 'N/A', and date not in future beyond today)
    $today_str = date('Y-m-d');
    $is_job_despatched = ($status === 'despatched' && !empty($job_desp_date) && $job_desp_date <= $today_str && $u !== 'Assign' && $u !== 'N/A' && $u !== '-');

    if (!$is_subtask_done && !$is_qc_done && !$is_job_despatched) {
        return ['is_completed' => false, 'date' => ''];
    }

    // Determine completion date
    $comp_date = '';

    // If subtask string contains a date (e.g. 26-11, 26.11, 26/11)
    if ($is_subtask_done && preg_match('/(\d{1,2})[-.\/](\d{1,2})(?:[-.\/](\d{2,4}))?/', $u, $matches)) {
        $day = (int)$matches[1];
        $month = (int)$matches[2];
        $year = !empty($matches[3]) ? (int)$matches[3] : $fallback_year;
        if ($year < 100) $year += 2000;
        if (checkdate($month, $day, $year)) {
            $comp_date = sprintf('%04d-%02d-%02d', $year, $month, $day);
        }
    }

    if (empty($comp_date)) {
        if (!empty($job_desp_date) && $job_desp_date <= $today_str) {
            $comp_date = $job_desp_date;
        } elseif (!empty($job_rec_date)) {
            $comp_date = $job_rec_date;
        } else {
            $comp_date = $today_str;
        }
    }

    return ['is_completed' => true, 'date' => $comp_date];
}

// -------------------------------------------------------------------
// 1. DATE / MONTH SELECTION
// -------------------------------------------------------------------
$current_time = time();
$default_year = (int)date('Y', $current_time);
$default_month = (int)date('m', $current_time);

// Check if user has specific month/year in GET
$selected_year = isset($_GET['year']) ? (int)$_GET['year'] : $default_year;
$selected_month = isset($_GET['month']) ? (int)$_GET['month'] : $default_month;

if ($selected_year < 2000 || $selected_year > 2099) $selected_year = $default_year;
if ($selected_month < 1 || $selected_month > 12) $selected_month = $default_month;

$days_in_month = cal_days_in_month(CAL_GREGORIAN, $selected_month, $selected_year);
$month_start_date = sprintf('%04d-%02d-01', $selected_year, $selected_month);
$month_end_date   = sprintf('%04d-%02d-%02d', $selected_year, $selected_month, $days_in_month);
$month_label      = date('F Y', strtotime($month_start_date));
$prev_month_ts    = strtotime("-1 month", strtotime($month_start_date));
$prev_year        = (int)date('Y', $prev_month_ts);
$prev_month       = (int)date('m', $prev_month_ts);
$next_month_ts    = strtotime("+1 month", strtotime($month_start_date));
$next_year        = (int)date('Y', $next_month_ts);
$next_month       = (int)date('m', $next_month_ts);

// Filter to toggle all days vs only active days
$filter_active_only = isset($_GET['active_only']) && $_GET['active_only'] === '1';

// -------------------------------------------------------------------
// 2. FETCH JOBS DATA DIRECTLY FROM UQ PROCESS DATABASE
// -------------------------------------------------------------------
$sql = "SELECT 
            j.job_id, j.job_date, j.dispatch_date, j.client_id, j.article,
            c.client_name, c.team_client_name,
            cjt.rec_date, cjt.articles,
            cjt.nlm, cjt.jats, cjt.clockss, cjt.scielo, cjt.ebsco,
            cjt.pubmed, cjt.biblat, cjt.doaj,
            cjt.html, cjt.new_html, cjt.direct_html,
            cjt.nlm_user, cjt.jats_user, cjt.clockss_user, cjt.Scielo_user, cjt.ebsco_user,
            cjt.pubmed_user, cjt.biblat_user, cjt.doaj_user,
            cjt.HTML_user, cjt.new_html_user, cjt.direct_html_user,
            cjt.job_status, cjt.despatched_date, cjt.QC_user
        FROM unique_xml_jobs cjt
        JOIN jobs j ON cjt.job_id = j.job_id
        LEFT JOIN client_master c ON j.client_id = c.client_id
        ORDER BY j.job_id ASC";

$result = $mysqli->query($sql);
$all_jobs = [];
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $all_jobs[] = $row;
    }
}

// -------------------------------------------------------------------
// 3. AGGREGATE CALCULATIONS
// -------------------------------------------------------------------
// Initialize daily buckets
$daily_data = [];
for ($d = 1; $d <= $days_in_month; $d++) {
    $dt_str = sprintf('%04d-%02d-%02d', $selected_year, $selected_month, $d);
    $daily_data[$dt_str] = [
        'day'             => $d,
        'date_str'        => $dt_str,
        'date_label'      => date('d-M', strtotime($dt_str)),
        'day_name'        => date('D', strtotime($dt_str)),
        'is_weekend'      => in_array(date('N', strtotime($dt_str)), [6, 7]),
        'has_activity'    => false,
        // XML (NLM, JATS, CLOCKSS, SCIELO, EBSCO)
        'xml_in'          => 0,
        'xml_out'         => 0,
        // PubMed / Biblat / DOAJ
        'pubmed_in'       => 0,
        'pubmed_out'      => 0,
        // HTML (HTML, New HTML)
        'html_in'         => 0,
        'html_out'        => 0,
        // Direct HTML
        'direct_html_in'  => 0,
        'direct_html_out' => 0,
        // Daily Combined
        'total_in'        => 0,
        'total_out'       => 0,
        'closing_backlog' => 0
    ];
}

$opening_backlog   = 0;
$total_input_month  = 0;
$total_output_month = 0;

$process_totals = [
    'xml'         => ['in' => 0, 'out' => 0],
    'pubmed'      => ['in' => 0, 'out' => 0],
    'html'        => ['in' => 0, 'out' => 0],
    'direct_html' => ['in' => 0, 'out' => 0]
];

foreach ($all_jobs as $job) {
    // Determine Job Received Date
    $rec_day = parseToDateStr($job['rec_date']);
    if (empty($rec_day)) {
        $rec_day = parseToDateStr($job['job_date']);
    }

    // Determine Job Despatched Date
    $desp_day = parseToDateStr($job['despatched_date']);
    if (empty($desp_day)) {
        $desp_day = parseToDateStr($job['dispatch_date']);
    }

    $status  = strtolower(trim((string)($job['job_status'] ?? '')));
    $qc_user = trim((string)($job['QC_user'] ?? ''));

    // Define all 4 categories and their respective subtasks
    $subtasks = [
        // XML (NLM, JATS, CLOCKSS, SCIELO, EBSCO)
        ['cat' => 'xml',         'qty' => cleanNum($job['nlm']),         'user' => $job['nlm_user'] ?? ''],
        ['cat' => 'xml',         'qty' => cleanNum($job['jats']),        'user' => $job['jats_user'] ?? ''],
        ['cat' => 'xml',         'qty' => cleanNum($job['clockss']),     'user' => $job['clockss_user'] ?? ''],
        ['cat' => 'xml',         'qty' => cleanNum($job['scielo']),      'user' => $job['Scielo_user'] ?? ($job['scielo_user'] ?? '')],
        ['cat' => 'xml',         'qty' => cleanNum($job['ebsco']),       'user' => $job['ebsco_user'] ?? ''],

        // PUBMED / BIBLAT / DOAJ
        ['cat' => 'pubmed',      'qty' => cleanNum($job['pubmed']),      'user' => $job['pubmed_user'] ?? ''],
        ['cat' => 'pubmed',      'qty' => cleanNum($job['biblat']),      'user' => $job['biblat_user'] ?? ''],
        ['cat' => 'pubmed',      'qty' => cleanNum($job['doaj']),        'user' => $job['doaj_user'] ?? ''],

        // HTML (HTML, NEW HTML)
        ['cat' => 'html',        'qty' => cleanNum($job['html']),        'user' => $job['HTML_user'] ?? ($job['html_user'] ?? '')],
        ['cat' => 'html',        'qty' => cleanNum($job['new_html']),    'user' => $job['new_html_user'] ?? ''],

        // DIRECT HTML
        ['cat' => 'direct_html', 'qty' => cleanNum($job['direct_html']), 'user' => $job['direct_html_user'] ?? '']
    ];

    foreach ($subtasks as $st) {
        $qty = $st['qty'];
        if ($qty <= 0) continue;

        $cat      = $st['cat'];
        $user_val = $st['user'];

        // Evaluate task completion and completion date
        $comp    = getTaskCompletionInfo($user_val, $qty, $status, $qc_user, $desp_day, $rec_day, $selected_year);
        $is_comp = $comp['is_completed'];
        $comp_dt = $comp['date'];

        // 1. OPENING BACKLOG CALCULATION
        // Any subtask received prior to current month that was NOT completed prior to month start
        if (!empty($rec_day) && $rec_day < $month_start_date) {
            $completed_before_month = ($is_comp && !empty($comp_dt) && $comp_dt < $month_start_date);
            if (!$completed_before_month) {
                $opening_backlog += $qty;
            }
        }

        // 2. CURRENT MONTH DAILY INPUTS
        if (!empty($rec_day) && isset($daily_data[$rec_day])) {
            $daily_data[$rec_day][$cat . '_in'] += $qty;
            $daily_data[$rec_day]['total_in']   += $qty;
            $daily_data[$rec_day]['has_activity'] = true;

            $process_totals[$cat]['in'] += $qty;
            $total_input_month          += $qty;
        }

        // 3. CURRENT MONTH DAILY OUTPUTS (Completed / Despatched)
        if ($is_comp && !empty($comp_dt) && isset($daily_data[$comp_dt])) {
            $daily_data[$comp_dt][$cat . '_out'] += $qty;
            $daily_data[$comp_dt]['total_out']   += $qty;
            $daily_data[$comp_dt]['has_activity'] = true;

            $process_totals[$cat]['out'] += $qty;
            $total_output_month          += $qty;
        }
    }
}

// Calculate Day-by-Day Running Closing Backlog
$running_backlog = $opening_backlog;
foreach ($daily_data as $dt_str => &$day_row) {
    $running_backlog = $running_backlog + $day_row['total_in'] - $day_row['total_out'];
    $day_row['closing_backlog'] = $running_backlog;
}
unset($day_row);

// Final Closing Backlog for Month
$closing_backlog = $opening_backlog + $total_input_month - $total_output_month;
$monthly_achievement = $total_output_month;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>UQ Summary - Team Production - Tapasi ERP</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="../custom_style.css?v=<?= file_exists(__DIR__ . '/../custom_style.css') ? filemtime(__DIR__ . '/../custom_style.css') : 1 ?>">

    <style>
        :root {
            --uq-header-bg: #5e6b77;
            --uq-sub-header: #4f5a65;
            --uq-border: #dee2e6;
            --uq-stripe-even: #f2f4f6;
            --uq-stripe-odd: #ffffff;
            --uq-hover-bg: #e9ecef;
            --uq-bg: #f5f7fa;
        }

        body {
            background-color: var(--uq-bg);
            font-family: var(--erp-font-family, 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif);
            font-size: 12.5px;
            color: #212529;
        }

        .main-content {
            margin-top: 75px;
            padding: 15px 3%;
        }

        /* Top 4 Production Cards - Vibrant With Cursor Lift */
        .prod-card {
            border: none;
            border-radius: 12px;
            padding: 20px 24px;
            transition: transform 0.3s cubic-bezier(0.165, 0.84, 0.44, 1), box-shadow 0.3s ease;
            position: relative;
            overflow: hidden;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            height: 100%;
            cursor: pointer;
        }

        .prod-card:hover {
            transform: translateY(-8px) scale(1.01);
            box-shadow: 0 14px 28px rgba(0, 0, 0, 0.18) !important;
        }

        /* Card 1: Monthly Achievement - Emerald Green */
        .prod-card.card-achievement {
            background: linear-gradient(135deg, #10b981 0%, #059669 100%);
            color: #ffffff;
            box-shadow: 0 6px 20px rgba(16, 185, 129, 0.32);
        }

        /* Card 2: Opening Backlog - Royal Indigo */
        .prod-card.card-opening {
            background: linear-gradient(135deg, #6366f1 0%, #4338ca 100%);
            color: #ffffff;
            box-shadow: 0 6px 20px rgba(99, 102, 241, 0.32);
        }

        /* Card 3: Total Input - Ocean Blue */
        .prod-card.card-input {
            background: linear-gradient(135deg, #0284c7 0%, #0369a1 100%);
            color: #ffffff;
            box-shadow: 0 6px 20px rgba(2, 132, 199, 0.32);
        }

        /* Card 4: Closing Backlog - Warm Amber / Bronze */
        .prod-card.card-closing {
            background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%);
            color: #ffffff;
            box-shadow: 0 6px 20px rgba(245, 158, 11, 0.32);
        }

        .card-lbl {
            font-size: 13.5px;
            font-weight: 700;
            color: rgba(255, 255, 255, 0.95);
            margin-bottom: 8px;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }

        .card-val {
            font-size: 34px;
            font-weight: 800;
            letter-spacing: -0.5px;
            line-height: 1.1;
            color: #ffffff;
        }

        .card-pill-tag {
            background: rgba(255, 255, 255, 0.22);
            color: #ffffff;
            padding: 3px 10px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 600;
            backdrop-filter: blur(4px);
        }

        /* Filter Banner */
        .filter-banner {
            background-color: var(--uq-header-bg);
            border-radius: 12px;
            padding: 14px 20px;
            color: #ffffff;
            box-shadow: 0 4px 14px rgba(0,0,0,0.08);
            margin-bottom: 20px;
        }

        .filter-banner select, .filter-banner input {
            font-size: 12.5px;
            height: 34px;
            border-radius: 6px;
        }

        /* EXACT UQ PROCESS TABLE STYLES (MATCHING xml/xml_unique_start.php) */
        .table-card {
            background: #ffffff;
            border-radius: 8px;
            box-shadow: 0 1px 10px rgba(0,0,0,0.05);
            border: 1px solid #dee2e6;
            overflow: hidden;
            margin-bottom: 25px;
        }

        .table-card-header {
            padding: 12px 18px;
            background: #ffffff;
            border-bottom: 1px solid #dee2e6;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        /* Grid Table Styles - Full Width Wide Table matching xml_unique_start.php */
        .table-responsive-custom,
        .table-responsive { 
            max-height: 65vh; 
            overflow-y: auto !important; 
            overflow-x: auto !important; 
            scrollbar-color: #6c757d #f1f1f1 !important;
            scrollbar-width: thin !important;
        }
        .table-responsive-custom::-webkit-scrollbar,
        .table-responsive::-webkit-scrollbar {
            width: 10px !important;
            height: 10px !important;
        }
        .table-responsive-custom::-webkit-scrollbar-track,
        .table-responsive::-webkit-scrollbar-track {
            background: #f1f1f1 !important;
            border-radius: 4px;
        }
        .table-responsive-custom::-webkit-scrollbar-thumb,
        .table-responsive::-webkit-scrollbar-thumb {
            background: #6c757d !important;
            border-radius: 4px;
        }
        .table-responsive-custom::-webkit-scrollbar-thumb:hover,
        .table-responsive::-webkit-scrollbar-thumb:hover {
            background: #5a6268 !important;
        }

        /* Headers matching xml_unique_start.php (.table th) */
        .sheet-table, .uq-style-table {
            width: 100%;
            border-collapse: separate;
            border-spacing: 0;
            font-size: 12.5px;
        }

        .sheet-table thead th,
        .uq-style-table thead th,
        .table th {
            background-color: #5e6b77 !important;
            color: #ffffff !important;
            font-weight: 600 !important;
            font-size: 12.5px !important;
            padding: 8px 10px !important;
            white-space: nowrap !important;
            position: sticky !important;
            top: 0 !important;
            z-index: 20 !important;
            text-align: center !important;
            border-top: 1px solid #dee2e6 !important;
            border-right: 1px solid #dee2e6 !important;
            border-bottom: 1px solid #dee2e6 !important;
        }

        .sheet-table thead tr.sub-header th,
        .uq-style-table thead tr.sub-header th {
            background-color: #5e6b77 !important;
            color: #ffffff !important;
            border-top: 1px solid #dee2e6 !important;
            border-right: 1px solid #dee2e6 !important;
            border-bottom: 1px solid #dee2e6 !important;
            font-size: 11.5px !important;
            top: 36px !important;
            z-index: 19 !important;
        }

        /* Cells matching xml_unique_start.php (#trackingTable tbody td) */
        .sheet-table tbody td,
        .uq-style-table tbody td,
        .table td {
            vertical-align: middle !important;
            text-align: center !important;
            border-bottom: 1px solid #dee2e6 !important;
            border-right: 1px solid #dee2e6 !important;
            padding: 6px 10px !important;
            white-space: nowrap !important;
            font-size: 12.5px !important;
            transition: background-color 0.15s ease;
        }

        .sheet-table thead th:first-child,
        .sheet-table tbody td:first-child,
        .uq-style-table thead th:first-child,
        .uq-style-table tbody td:first-child {
            border-left: 1px solid #dee2e6 !important;
        }

        /* Zebra Striping (matching xml_unique_start.php) - Exclude Week and Month Total Rows */
        .sheet-table tbody tr:not(.week-total-row):not(.month-total-row):nth-child(even) td,
        .uq-style-table tbody tr:not(.week-row-styled):not(.month-row-styled):nth-child(even) td {
            background-color: #f2f4f6 !important;
        }

        .sheet-table tbody tr:not(.week-total-row):not(.month-total-row):nth-child(odd) td,
        .uq-style-table tbody tr:not(.week-row-styled):not(.month-row-styled):nth-child(odd) td {
            background-color: #ffffff !important;
        }

        /* Row Hover State matching xml_unique_start.php */
        .sheet-table tbody tr:not(.month-total-row):hover td,
        .uq-style-table tbody tr:not(.month-row-styled):hover td {
            background-color: #e9ecef !important;
        }

        /* Sticky Date Column (matching Sticky Col 1/2 in xml_unique_start.php) */
        .date-col {
            font-weight: 600;
            text-align: left !important;
            padding-left: 14px !important;
            position: sticky !important;
            left: 0 !important;
            z-index: 10 !important;
            border-right: 1px solid #dee2e6 !important;
        }

        .sheet-table tbody tr:not(.week-total-row):not(.month-total-row):nth-child(even) .date-col,
        .uq-style-table tbody tr:not(.week-row-styled):not(.month-row-styled):nth-child(even) .date-col {
            background-color: #f2f4f6 !important;
        }

        .sheet-table tbody tr:not(.week-total-row):not(.month-total-row):nth-child(odd) .date-col,
        .uq-style-table tbody tr:not(.week-row-styled):not(.month-row-styled):nth-child(odd) .date-col {
            background-color: #ffffff !important;
        }

        .sheet-table tbody tr:not(.month-total-row):hover .date-col,
        .uq-style-table tbody tr:not(.month-row-styled):hover .date-col {
            background-color: #e9ecef !important;
        }

        /* Week Total Row - Clean, light, minimal borders like UQ Process */
        .sheet-table tbody tr.week-total-row td,
        .uq-style-table tbody tr.week-row-styled td {
            background-color: #f0f3f6 !important;
            font-weight: 700 !important;
            color: #2c3e50 !important;
            border-top: 1px solid #dee2e6 !important;
            border-bottom: 1px solid #dee2e6 !important;
            border-right: 1px solid #dee2e6 !important;
        }

        .sheet-table tbody tr.week-total-row:hover td,
        .uq-style-table tbody tr.week-row-styled:hover td {
            background-color: #e6ebf0 !important;
        }

        /* Bottom Total Row - Frozen / Sticky at bottom, light blue-grey background, NO dark grey */
        .sheet-table tbody tr.month-total-row,
        .uq-style-table tbody tr.month-row-styled {
            position: sticky !important;
            bottom: 0 !important;
            z-index: 25 !important;
        }

        .sheet-table tbody tr.month-total-row td,
        .uq-style-table tbody tr.month-row-styled td {
            background-color: #dbeafe !important;
            color: #0f172a !important;
            font-weight: 800 !important;
            font-size: 13px !important;
            border-top: 2px solid #93c5fd !important;
            border-bottom: 2px solid #93c5fd !important;
            border-right: 1px solid #bfdbfe !important;
            box-shadow: 0 -2px 5px rgba(0, 0, 0, 0.08) !important;
        }

        .sheet-table tbody tr.month-total-row .date-col,
        .uq-style-table tbody tr.month-row-styled .date-col {
            background-color: #dbeafe !important;
            color: #1e40af !important;
            font-weight: 800 !important;
            z-index: 26 !important;
        }

        .sheet-table tbody tr.month-total-row:hover td,
        .uq-style-table tbody tr.month-row-styled:hover td {
            background-color: #bfdbfe !important;
            color: #0f172a !important;
        }

        /* Section Nav Tabs */
        .nav-pills-custom .nav-link {
            border-radius: 8px;
            color: #64748b;
            font-weight: 600;
            padding: 7px 16px;
            margin-right: 6px;
            transition: all 0.2s;
            border: 1px solid transparent;
        }

        .nav-pills-custom .nav-link:hover {
            background: #f1f5f9;
            color: #334155;
        }

        .nav-pills-custom .nav-link.active {
            background: #5e6b77;
            color: #ffffff;
            box-shadow: 0 2px 8px rgba(94, 107, 119, 0.35);
        }
    </style>
</head>
<body>
    <?php require 'header.php'; ?>

    <div class="main-content">
        <!-- TOP BANNER WITH MONTH PICKER & ACTIONS -->
        <div class="filter-banner">
            <div class="row align-items-center g-3">
                <div class="col-md-5">
                    <div class="d-flex align-items-center">
                        <div class="bg-white bg-opacity-10 p-2 rounded-3 me-3">
                            <i class="fas fa-chart-line fa-2x text-white"></i>
                        </div>
                        <div>
                            <h4 class="fw-bold mb-0 text-white" style="font-size: 18px; letter-spacing: -0.3px;">
                                TEAM PRODUCTION – <?= strtoupper($month_label) ?>
                            </h4>
                        </div>
                    </div>
                </div>

                <div class="col-md-7">
                    <form method="GET" class="d-flex flex-wrap align-items-center justify-content-md-end gap-2">
                        <!-- Prev Month Button -->
                        <a href="?year=<?= $prev_year ?>&month=<?= $prev_month ?><?= $filter_active_only ? '&active_only=1' : '' ?>" class="btn btn-sm btn-outline-light" title="Previous Month">
                            <i class="fas fa-chevron-left"></i>
                        </a>

                        <!-- Month Dropdown -->
                        <select name="month" class="form-select form-select-sm bg-white text-dark w-auto">
                            <?php for ($m = 1; $m <= 12; $m++): ?>
                                <option value="<?= $m ?>" <?= $selected_month === $m ? 'selected' : '' ?>>
                                    <?= date('F', mktime(0, 0, 0, $m, 10)) ?>
                                </option>
                            <?php endfor; ?>
                        </select>

                        <!-- Year Dropdown -->
                        <select name="year" class="form-select form-select-sm bg-white text-dark w-auto">
                            <?php for ($y = 2024; $y <= 2030; $y++): ?>
                                <option value="<?= $y ?>" <?= $selected_year === $y ? 'selected' : '' ?>>
                                    <?= $y ?>
                                </option>
                            <?php endfor; ?>
                        </select>

                        <!-- Button Renamed to Apply Filter as requested with Blue background -->
                        <button type="submit" class="btn btn-sm btn-primary fw-bold text-white shadow-sm" style="background-color: #0d6efd; border-color: #0d6efd;">
                            <i class="fas fa-filter me-1"></i> Apply Filters
                        </button>
                    </form>
                </div>
            </div>
        </div>

        <!-- TOP 4 STAT CARDS - VIBRANT WITH CURSOR LIFT ANIMATION -->
        <div class="row g-3 mb-4">
            <!-- 1. Monthly Achievement -->
            <div class="col-lg-3 col-sm-6">
                <div class="prod-card card-achievement">
                    <div class="card-lbl">
                        <span><i class="fas fa-trophy me-2"></i> Monthly Achievement</span>
                        <span class="card-pill-tag">Completed</span>
                    </div>
                    <div class="card-val"><?= number_format($monthly_achievement) ?></div>
                </div>
            </div>

            <!-- 2. Opening Backlog -->
            <div class="col-lg-3 col-sm-6">
                <div class="prod-card card-opening">
                    <div class="card-lbl">
                        <span><i class="fas fa-hourglass-start me-2"></i> Opening Backlog</span>
                        <span class="card-pill-tag">Prior Month</span>
                    </div>
                    <div class="card-val"><?= number_format($opening_backlog) ?></div>
                </div>
            </div>

            <!-- 3. Total Input -->
            <div class="col-lg-3 col-sm-6">
                <div class="prod-card card-input">
                    <div class="card-lbl">
                        <span><i class="fas fa-inbox me-2"></i> Total Input</span>
                        <span class="card-pill-tag"><?= date('M Y', strtotime($month_start_date)) ?></span>
                    </div>
                    <div class="card-val"><?= number_format($total_input_month) ?></div>
                </div>
            </div>

            <!-- 4. Closing Backlog - Changed to Warm Amber / Bronze -->
            <div class="col-lg-3 col-sm-6">
                <div class="prod-card card-closing">
                    <div class="card-lbl">
                        <span><i class="fas fa-flag-checkered me-2"></i> Closing Backlog</span>
                        <span class="card-pill-tag">Current Balance</span>
                    </div>
                    <div class="card-val"><?= number_format($closing_backlog) ?></div>
                </div>
            </div>
        </div>

        <!-- SECTION TABS -->
        <div class="mb-3">
            <ul class="nav nav-pills nav-pills-custom" id="summaryTabs" role="tablist">
                <li class="nav-item" role="presentation">
                    <button class="nav-link active" id="tab-sheet-btn" data-bs-toggle="pill" data-bs-target="#tab-sheet" type="button" role="tab">
                        <i class="fas fa-table me-1"></i> Production Sheet
                    </button>
                </li>
                <li class="nav-item" role="presentation">
                    <button class="nav-link" id="tab-daily-btn" data-bs-toggle="pill" data-bs-target="#tab-daily" type="button" role="tab">
                        <i class="fas fa-calculator me-1"></i> Daily Production Summary
                    </button>
                </li>
                <li class="nav-item" role="presentation">
                    <button class="nav-link" id="tab-target-btn" data-bs-toggle="pill" data-bs-target="#tab-target" type="button" role="tab">
                        <i class="fas fa-bullseye me-1"></i> Target Achievement & Monthly Summary
                    </button>
                </li>
            </ul>
        </div>

        <div class="tab-content" id="summaryTabsContent">

            <!-- =================================================================== -->
            <!-- TAB 1: PRODUCTION SHEET                                             -->
            <!-- =================================================================== -->
            <div class="tab-pane fade show active" id="tab-sheet" role="tabpanel">
                <div class="table-card">
                    <div class="table-card-header">
                        <div>
                            <h6 class="fw-bold mb-0 text-dark">
                                <i class="fas fa-calendar-alt text-secondary me-2"></i> Team Production – <?= $month_label ?>
                            </h6>
                            <span class="text-muted" style="font-size: 11px;">
                                Opening backlog: <strong class="text-danger"><?= number_format($opening_backlog) ?></strong>
                            </span>
                        </div>
                        <div>
                            <span class="badge bg-secondary py-1 px-3" style="font-size: 11px;">
                                Monthly Report
                            </span>
                        </div>
                    </div>

                    <div class="table-responsive-custom">
                        <table class="table table-bordered sheet-table mb-0">
                            <thead>
                                <tr>
                                    <th rowspan="2" class="date-col" style="min-width: 120px;">DATE</th>
                                    <th colspan="3" style="border-right: 2px solid #374151;">XML (NLM, JATS, CLOCKSS, SCIELO, EBSCO)</th>
                                    <th colspan="3" style="border-right: 2px solid #374151;">PUBMED / BIBLAT / DOAJ</th>
                                    <th colspan="3" style="border-right: 2px solid #374151;">HTML (HTML, NEW HTML)</th>
                                    <th colspan="3">DIRECT HTML</th>
                                </tr>
                                <tr class="sub-header">
                                    <!-- XML -->
                                    <th style="min-width: 65px;">I/P</th>
                                    <th style="min-width: 65px;">O/P</th>
                                    <th style="min-width: 65px; border-right: 2px solid #374151;">PENDING</th>
                                    <!-- PubMed -->
                                    <th style="min-width: 65px;">I/P</th>
                                    <th style="min-width: 65px;">O/P</th>
                                    <th style="min-width: 65px; border-right: 2px solid #374151;">PENDING</th>
                                    <!-- HTML -->
                                    <th style="min-width: 65px;">I/P</th>
                                    <th style="min-width: 65px;">O/P</th>
                                    <th style="min-width: 65px; border-right: 2px solid #374151;">PENDING</th>
                                    <!-- Direct HTML -->
                                    <th style="min-width: 65px;">I/P</th>
                                    <th style="min-width: 65px;">O/P</th>
                                    <th style="min-width: 65px;">PENDING</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php
                                $grand_totals = [
                                    'xml_in' => 0, 'xml_out' => 0,
                                    'pubmed_in' => 0, 'pubmed_out' => 0,
                                    'html_in' => 0, 'html_out' => 0,
                                    'direct_html_in' => 0, 'direct_html_out' => 0
                                ];

                                $week_totals = [
                                    'xml_in' => 0, 'xml_out' => 0,
                                    'pubmed_in' => 0, 'pubmed_out' => 0,
                                    'html_in' => 0, 'html_out' => 0,
                                    'direct_html_in' => 0, 'direct_html_out' => 0
                                ];

                                foreach ($daily_data as $dt => $day_info):
                                    $day_idx = $day_info['day'];
                                    $is_sunday = ($day_info['day_name'] === 'Sun');

                                    // Accumulate week totals and grand totals (including any weekend input/output)
                                    $week_totals['xml_in']          += $day_info['xml_in'];
                                    $week_totals['xml_out']         += $day_info['xml_out'];
                                    $week_totals['pubmed_in']       += $day_info['pubmed_in'];
                                    $week_totals['pubmed_out']      += $day_info['pubmed_out'];
                                    $week_totals['html_in']         += $day_info['html_in'];
                                    $week_totals['html_out']        += $day_info['html_out'];
                                    $week_totals['direct_html_in']  += $day_info['direct_html_in'];
                                    $week_totals['direct_html_out'] += $day_info['direct_html_out'];

                                    $grand_totals['xml_in']          += $day_info['xml_in'];
                                    $grand_totals['xml_out']         += $day_info['xml_out'];
                                    $grand_totals['pubmed_in']       += $day_info['pubmed_in'];
                                    $grand_totals['pubmed_out']      += $day_info['pubmed_out'];
                                    $grand_totals['html_in']         += $day_info['html_in'];
                                    $grand_totals['html_out']        += $day_info['html_out'];
                                    $grand_totals['direct_html_in']  += $day_info['direct_html_in'];
                                    $grand_totals['direct_html_out'] += $day_info['direct_html_out'];

                                    // Check if Saturday (end of standard operational week) or last day of month
                                    $is_week_end = ($day_info['day_name'] === 'Sat' || $day_idx === $days_in_month);

                                    // SKIP SUNDAY ROW DISPLAY AS REQUESTED
                                    if ($is_sunday) {
                                        if ($is_week_end) {
                                            $curr_fiscal_w = calculateFiscalWeekNumber($dt);
                                            // output week row if month ends on sunday
                                        }
                                        continue;
                                    }

                                    $xml_pending         = $day_info['xml_in'] - $day_info['xml_out'];
                                    $pubmed_pending      = $day_info['pubmed_in'] - $day_info['pubmed_out'];
                                    $html_pending        = $day_info['html_in'] - $day_info['html_out'];
                                    $direct_html_pending = $day_info['direct_html_in'] - $day_info['direct_html_out'];

                                    $has_xml_act    = ($day_info['xml_in'] > 0 || $day_info['xml_out'] > 0);
                                    $has_pubmed_act = ($day_info['pubmed_in'] > 0 || $day_info['pubmed_out'] > 0);
                                    $has_html_act   = ($day_info['html_in'] > 0 || $day_info['html_out'] > 0);
                                    $has_dhtml_act  = ($day_info['direct_html_in'] > 0 || $day_info['direct_html_out'] > 0);

                                    // If filter_active_only is true, skip rows with 0 activity
                                    if ($filter_active_only && !$day_info['has_activity']) {
                                        if ($is_week_end) {
                                            $week_totals = [
                                                'xml_in' => 0, 'xml_out' => 0,
                                                'pubmed_in' => 0, 'pubmed_out' => 0,
                                                'html_in' => 0, 'html_out' => 0,
                                                'direct_html_in' => 0, 'direct_html_out' => 0
                                            ];
                                        }
                                        continue;
                                    }
                                ?>
                                    <tr class="<?= $day_info['has_activity'] ? 'row-has-data' : '' ?>">
                                        <!-- Clean Date Column (Sundays skipped) -->
                                        <td class="date-col">
                                            <?= $day_info['date_label'] ?>
                                            <span class="text-muted" style="font-size: 11px; font-weight: normal;">(<?= $day_info['day_name'] ?>)</span>
                                        </td>

                                        <!-- XML -->
                                        <td><?= fmtVal($day_info['xml_in']) ?></td>
                                        <td><?= fmtVal($day_info['xml_out']) ?></td>
                                        <td style="border-right: 2px solid #dee2e6;"><?= fmtPending($xml_pending, $has_xml_act) ?></td>

                                        <!-- PubMed / DOAJ -->
                                        <td><?= fmtVal($day_info['pubmed_in']) ?></td>
                                        <td><?= fmtVal($day_info['pubmed_out']) ?></td>
                                        <td style="border-right: 2px solid #dee2e6;"><?= fmtPending($pubmed_pending, $has_pubmed_act) ?></td>

                                        <!-- HTML -->
                                        <td><?= fmtVal($day_info['html_in']) ?></td>
                                        <td><?= fmtVal($day_info['html_out']) ?></td>
                                        <td style="border-right: 2px solid #dee2e6;"><?= fmtPending($html_pending, $has_html_act) ?></td>

                                        <!-- Direct HTML -->
                                        <td><?= fmtVal($day_info['direct_html_in']) ?></td>
                                        <td><?= fmtVal($day_info['direct_html_out']) ?></td>
                                        <td><?= fmtPending($direct_html_pending, $has_dhtml_act) ?></td>
                                    </tr>

                                    <?php
                                    // Check if end of week (Saturday or last day of month)
                                    if ($is_week_end):
                                        $curr_fiscal_w         = calculateFiscalWeekNumber($dt);
                                        $w_xml_pending         = $week_totals['xml_in'] - $week_totals['xml_out'];
                                        $w_pubmed_pending      = $week_totals['pubmed_in'] - $week_totals['pubmed_out'];
                                        $w_html_pending        = $week_totals['html_in'] - $week_totals['html_out'];
                                        $w_direct_html_pending = $week_totals['direct_html_in'] - $week_totals['direct_html_out'];

                                        $w_has_xml    = ($week_totals['xml_in'] > 0 || $week_totals['xml_out'] > 0);
                                        $w_has_pubmed = ($week_totals['pubmed_in'] > 0 || $week_totals['pubmed_out'] > 0);
                                        $w_has_html   = ($week_totals['html_in'] > 0 || $week_totals['html_out'] > 0);
                                        $w_has_dhtml  = ($week_totals['direct_html_in'] > 0 || $week_totals['direct_html_out'] > 0);
                                    ?>
                                        <tr class="week-total-row">
                                            <td class="date-col">
                                                <i class="fas fa-calendar-week me-1 text-secondary"></i> Week <?= $curr_fiscal_w ?> Total
                                            </td>

                                            <!-- XML -->
                                            <td><?= fmtVal($week_totals['xml_in']) ?></td>
                                            <td><?= fmtVal($week_totals['xml_out']) ?></td>
                                            <td style="border-right: 2px solid #dee2e6;"><?= fmtPending($w_xml_pending, $w_has_xml) ?></td>

                                            <!-- PubMed -->
                                            <td><?= fmtVal($week_totals['pubmed_in']) ?></td>
                                            <td><?= fmtVal($week_totals['pubmed_out']) ?></td>
                                            <td style="border-right: 2px solid #dee2e6;"><?= fmtPending($w_pubmed_pending, $w_has_pubmed) ?></td>

                                            <!-- HTML -->
                                            <td><?= fmtVal($week_totals['html_in']) ?></td>
                                            <td><?= fmtVal($week_totals['html_out']) ?></td>
                                            <td style="border-right: 2px solid #dee2e6;"><?= fmtPending($w_html_pending, $w_has_html) ?></td>

                                            <!-- Direct HTML -->
                                            <td><?= fmtVal($week_totals['direct_html_in']) ?></td>
                                            <td><?= fmtVal($week_totals['direct_html_out']) ?></td>
                                            <td><?= fmtPending($w_direct_html_pending, $w_has_dhtml) ?></td>
                                        </tr>
                                    <?php
                                        // Reset week accumulator
                                        $week_totals = [
                                            'xml_in' => 0, 'xml_out' => 0,
                                            'pubmed_in' => 0, 'pubmed_out' => 0,
                                            'html_in' => 0, 'html_out' => 0,
                                            'direct_html_in' => 0, 'direct_html_out' => 0
                                        ];
                                    endif;
                                endforeach;

                                $g_xml_pending         = $grand_totals['xml_in'] - $grand_totals['xml_out'];
                                $g_pubmed_pending      = $grand_totals['pubmed_in'] - $grand_totals['pubmed_out'];
                                $g_html_pending        = $grand_totals['html_in'] - $grand_totals['html_out'];
                                $g_direct_html_pending = $grand_totals['direct_html_in'] - $grand_totals['direct_html_out'];

                                $g_has_xml    = ($grand_totals['xml_in'] > 0 || $grand_totals['xml_out'] > 0);
                                $g_has_pubmed = ($grand_totals['pubmed_in'] > 0 || $grand_totals['pubmed_out'] > 0);
                                $g_has_html   = ($grand_totals['html_in'] > 0 || $grand_totals['html_out'] > 0);
                                $g_has_dhtml  = ($grand_totals['direct_html_in'] > 0 || $grand_totals['direct_html_out'] > 0);
                                ?>
                                <tr class="month-total-row">
                                    <td class="date-col">
                                        <i class="fas fa-award me-1"></i> Month Total
                                    </td>

                                    <!-- XML -->
                                    <td><?= number_format($grand_totals['xml_in']) ?></td>
                                    <td><?= number_format($grand_totals['xml_out']) ?></td>
                                    <td style="border-right: 2px solid #bfdbfe;"><?= fmtPending($g_xml_pending, $g_has_xml) ?></td>

                                    <!-- PubMed -->
                                    <td><?= number_format($grand_totals['pubmed_in']) ?></td>
                                    <td><?= number_format($grand_totals['pubmed_out']) ?></td>
                                    <td style="border-right: 2px solid #bfdbfe;"><?= fmtPending($g_pubmed_pending, $g_has_pubmed) ?></td>

                                    <!-- HTML -->
                                    <td><?= number_format($grand_totals['html_in']) ?></td>
                                    <td><?= number_format($grand_totals['html_out']) ?></td>
                                    <td style="border-right: 2px solid #bfdbfe;"><?= fmtPending($g_html_pending, $g_has_html) ?></td>

                                    <!-- Direct HTML -->
                                    <td><?= number_format($grand_totals['direct_html_in']) ?></td>
                                    <td><?= number_format($grand_totals['direct_html_out']) ?></td>
                                    <td><?= fmtPending($g_direct_html_pending, $g_has_dhtml) ?></td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <!-- =================================================================== -->
            <!-- TAB 2: DAILY PRODUCTION SUMMARY (IMPROVED UI & BOLD WEEK TOTALS)    -->
            <!-- =================================================================== -->
            <div class="tab-pane fade" id="tab-daily" role="tabpanel">
                <div class="row">
                    <div class="col-lg-8 mx-auto">
                        <div class="table-card shadow-sm border">
                            <div class="table-card-header bg-light">
                                <h6 class="fw-bold mb-0 text-dark">
                                    <i class="fas fa-calendar-day text-secondary me-2"></i> Daily Production Summary
                                </h6>
                            </div>

                            <div class="table-responsive-custom" style="max-height: 65vh;">
                                <table class="table table-bordered mb-0 text-center uq-style-table align-middle">
                                    <thead>
                                        <tr>
                                            <th class="text-start ps-3" style="min-width: 140px;">DATE</th>
                                            <th style="min-width: 110px;">TOTAL I/P</th>
                                            <th style="min-width: 110px;">TOTAL O/P</th>
                                            <th style="min-width: 130px;">CLOSING BACKLOG</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <!-- Opening Row -->
                                        <tr class="table-light fw-bold" style="background-color: #f8fafc;">
                                            <td class="text-start ps-3 text-secondary">
                                                <i class="fas fa-hourglass-start me-1 text-muted"></i> Opening
                                            </td>
                                            <td class="text-muted">—</td>
                                            <td class="text-muted">—</td>
                                            <td class="fw-bold fs-6 text-dark"><?= number_format($opening_backlog) ?></td>
                                        </tr>

                                        <?php 
                                        $w_inp = 0; $w_out = 0;
                                        $tot_day_in = 0; $tot_day_out = 0;

                                        foreach ($daily_data as $dt => $day_info): 
                                            $day_num = $day_info['day'];
                                            $is_sunday = ($day_info['day_name'] === 'Sun');

                                            $w_inp += $day_info['total_in'];
                                            $w_out += $day_info['total_out'];
                                            $tot_day_in += $day_info['total_in'];
                                            $tot_day_out += $day_info['total_out'];

                                            $is_week_end = ($day_info['day_name'] === 'Sat' || $day_num === $days_in_month);

                                            // Skip Sunday row display
                                            if ($is_sunday) {
                                                continue;
                                            }
                                        ?>
                                            <tr class="<?= $day_info['has_activity'] ? 'row-has-data' : '' ?>">
                                                <td class="text-start ps-3 font-monospace">
                                                    <?= $day_info['date_label'] ?>
                                                    <span class="text-muted small">(<?= $day_info['day_name'] ?>)</span>
                                                </td>
                                                <td><?= fmtVal($day_info['total_in']) ?></td>
                                                <td><?= fmtVal($day_info['total_out']) ?></td>
                                                <td class="fw-bold <?= $day_info['closing_backlog'] > 0 ? 'text-dark' : 'text-success' ?>">
                                                    <?= number_format($day_info['closing_backlog']) ?>
                                                </td>
                                            </tr>

                                            <?php
                                            if ($is_week_end):
                                                $curr_fiscal_w = calculateFiscalWeekNumber($dt);
                                            ?>
                                                <tr class="week-row-styled">
                                                    <td class="text-start ps-3">
                                                        <i class="fas fa-calendar-week me-2 text-secondary"></i> Week <?= $curr_fiscal_w ?> Total
                                                    </td>
                                                    <td><?= number_format($w_inp) ?></td>
                                                    <td><?= number_format($w_out) ?></td>
                                                    <td><?= number_format($day_info['closing_backlog']) ?></td>
                                                </tr>
                                            <?php
                                                $w_inp = 0; $w_out = 0;
                                            endif;
                                        endforeach; 
                                        ?>
                                        <!-- Month Total Row (Frozen Sticky at Bottom) -->
                                        <tr class="month-row-styled">
                                            <td class="text-start ps-3">
                                                <i class="fas fa-award me-2"></i> Month Total
                                            </td>
                                            <td><?= number_format($tot_day_in) ?></td>
                                            <td><?= number_format($tot_day_out) ?></td>
                                            <td><?= number_format($closing_backlog) ?></td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- =================================================================== -->
            <!-- TAB 3: CATEGORY SUMMARY & TARGET ACHIEVEMENT                        -->
            <!-- =================================================================== -->
            <div class="tab-pane fade" id="tab-target" role="tabpanel">
                <div class="row g-4">
                    <!-- Category-wise Process Summary -->
                    <div class="col-lg-6">
                        <div class="table-card h-100">
                            <div class="table-card-header">
                                <h6 class="fw-bold mb-0 text-dark">
                                    <i class="fas fa-layer-group text-secondary me-2"></i> Category-wise Process Summary
                                </h6>
                                <span class="badge bg-secondary">Overview</span>
                            </div>

                            <div class="p-3">
                                <table class="table table-bordered mb-0 text-center align-middle uq-style-table" style="font-size: 12.5px;">
                                    <thead>
                                        <tr>
                                            <th class="text-start ps-3">PROCESS</th>
                                            <th>TOTAL I/P</th>
                                            <th>TOTAL O/P</th>
                                            <th>CLOSING PENDING</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <tr>
                                            <td class="text-start ps-3 fw-bold">XML</td>
                                            <td><?= number_format($process_totals['xml']['in']) ?></td>
                                            <td class="text-success fw-bold"><?= number_format($process_totals['xml']['out']) ?></td>
                                            <td class="fw-bold"><?= number_format($process_totals['xml']['in'] - $process_totals['xml']['out']) ?></td>
                                        </tr>
                                        <tr>
                                            <td class="text-start ps-3 fw-bold">PubMed / Biblat / DOAJ</td>
                                            <td><?= number_format($process_totals['pubmed']['in']) ?></td>
                                            <td class="text-success fw-bold"><?= number_format($process_totals['pubmed']['out']) ?></td>
                                            <td class="fw-bold"><?= number_format($process_totals['pubmed']['in'] - $process_totals['pubmed']['out']) ?></td>
                                        </tr>
                                        <tr>
                                            <td class="text-start ps-3 fw-bold">HTML</td>
                                            <td><?= number_format($process_totals['html']['in']) ?></td>
                                            <td class="text-success fw-bold"><?= number_format($process_totals['html']['out']) ?></td>
                                            <td class="fw-bold"><?= number_format($process_totals['html']['in'] - $process_totals['html']['out']) ?></td>
                                        </tr>
                                        <tr>
                                            <td class="text-start ps-3 fw-bold">Direct HTML</td>
                                            <td><?= number_format($process_totals['direct_html']['in']) ?></td>
                                            <td class="text-success fw-bold"><?= number_format($process_totals['direct_html']['out']) ?></td>
                                            <td class="fw-bold"><?= number_format($process_totals['direct_html']['in'] - $process_totals['direct_html']['out']) ?></td>
                                        </tr>
                                        <tr class="table-light fw-bold fs-6">
                                            <td class="text-start ps-3 text-dark">Team Total</td>
                                            <td><?= number_format($total_input_month) ?></td>
                                            <td class="text-success"><?= number_format($total_output_month) ?></td>
                                            <td class="text-danger"><?= number_format($total_input_month - $total_output_month) ?></td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>

                    <!-- Target Achievement Summary -->
                    <div class="col-lg-6">
                        <div class="table-card h-100">
                            <div class="table-card-header">
                                <h6 class="fw-bold mb-0 text-dark">
                                    <i class="fas fa-medal text-secondary me-2"></i> Target Achievement Summary
                                </h6>
                                <span class="badge bg-success">Performance</span>
                            </div>

                            <div class="p-4">
                                <h5 class="fw-bold text-dark mb-1"><?= $month_label ?> – Production Target</h5>
                                <div class="text-success fw-bold my-3" style="font-size: 38px; line-height: 1;">
                                    <?= number_format($monthly_achievement) ?> <span style="font-size: 18px; font-weight: normal; color: #64748b;">Pages</span>
                                </div>

                                <table class="table table-bordered text-center mb-0 uq-style-table">
                                    <thead>
                                        <tr>
                                            <th class="text-start ps-3">PROCESS</th>
                                            <th class="text-end pe-3">UPLOADED PAGES</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <tr>
                                            <td class="text-start ps-3">XML</td>
                                            <td class="text-end pe-3 fw-bold"><?= number_format($process_totals['xml']['out']) ?></td>
                                        </tr>
                                        <tr>
                                            <td class="text-start ps-3">PubMed / Biblat / DOAJ</td>
                                            <td class="text-end pe-3 fw-bold"><?= number_format($process_totals['pubmed']['out']) ?></td>
                                        </tr>
                                        <tr>
                                            <td class="text-start ps-3">HTML</td>
                                            <td class="text-end pe-3 fw-bold"><?= number_format($process_totals['html']['out']) ?></td>
                                        </tr>
                                        <tr>
                                            <td class="text-start ps-3">Direct HTML</td>
                                            <td class="text-end pe-3 fw-bold"><?= number_format($process_totals['direct_html']['out']) ?></td>
                                        </tr>
                                        <tr class="table-success fw-bold">
                                            <td class="text-start ps-3">Total Achievement</td>
                                            <td class="text-end pe-3 text-success fs-6"><?= number_format($monthly_achievement) ?></td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>

            </div>

        </div>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            let hash = window.location.hash;
            if (hash) {
                let triggerEl = document.querySelector('button[data-bs-target="' + hash + '"]');
                if (triggerEl) {
                    let tab = new bootstrap.Tab(triggerEl);
                    tab.show();
                }
            }
        });
    </script>
</body>
</html>
