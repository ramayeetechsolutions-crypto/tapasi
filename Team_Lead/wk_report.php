<br><br><br>
<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
ini_set('display_errors', 1);
error_reporting(E_ALL);

require 'db.php';

function h($s) { 
    return htmlspecialchars((string)$s, ENT_QUOTES); 
}

// Add New WK Report Action (Save to wk_report DB table)
if (isset($_POST['action']) && $_POST['action'] === 'add_report') {
    $report_date  = !empty($_POST['report_date']) ? $_POST['report_date'] : date('Y-m-d');
    $ce_articles  = (int)($_POST['ce_articles'] ?? 0);
    $ce_wc        = (int)($_POST['ce_wc'] ?? 0);
    $le_articles  = (int)($_POST['le_articles'] ?? 0);
    $le_wc        = (int)($_POST['le_wc'] ?? 0);
    $pag_articles = (int)($_POST['pag_articles'] ?? 0);
    $pag_pages    = (int)($_POST['pag_pages'] ?? 0);

    $stmt = $mysqli->prepare("INSERT INTO wk_report (report_date, ce_articles, ce_wc, le_articles, le_wc, pag_articles, pag_pages) 
                             VALUES (?, ?, ?, ?, ?, ?, ?)
                             ON DUPLICATE KEY UPDATE 
                             ce_articles = VALUES(ce_articles),
                             ce_wc = VALUES(ce_wc),
                             le_articles = VALUES(le_articles),
                             le_wc = VALUES(le_wc),
                             pag_articles = VALUES(pag_articles),
                             pag_pages = VALUES(pag_pages),
                             last_updated = NOW()");
    $stmt->bind_param("siiiiii", $report_date, $ce_articles, $ce_wc, $le_articles, $le_wc, $pag_articles, $pag_pages);
    $stmt->execute();
    header("Location: wk_report.php?msg=Report added successfully&type=success");
    exit;
}

// Month & Year Filter
$selected_month = isset($_GET['month']) ? (int)$_GET['month'] : (int)date('m');
$selected_year = isset($_GET['year']) ? (int)$_GET['year'] : (int)date('Y');

$days_in_month = cal_days_in_month(CAL_GREGORIAN, $selected_month, $selected_year);
$start_date = sprintf('%04d-%02d-01', $selected_year, $selected_month);
$end_date   = sprintf('%04d-%02d-%02d', $selected_year, $selected_month, $days_in_month);

// Data containers indexed by 'YYYY-MM-DD'
$report_data = [];
for ($d = 1; $d <= $days_in_month; $d++) {
    $cur_d = sprintf('%04d-%02d-%02d', $selected_year, $selected_month, $d);
    $report_data[$cur_d] = [
        'ce_art' => 0, 'ce_wc' => 0,
        'le_art' => 0, 'le_wc' => 0,
        'pag_art' => 0, 'pag_pages' => 0
    ];
}

// Fetch all rows from wk_report table for this month
$sql_rep = "SELECT * FROM wk_report WHERE report_date BETWEEN '$start_date' AND '$end_date'";
$res_rep = $mysqli->query($sql_rep);
if ($res_rep) {
    while ($r = $res_rep->fetch_assoc()) {
        $dt = $r['report_date'];
        if (isset($report_data[$dt])) {
            $report_data[$dt]['ce_art']   = (int)$r['ce_articles'];
            $report_data[$dt]['ce_wc']    = (int)$r['ce_wc'];
            $report_data[$dt]['le_art']   = (int)$r['le_articles'];
            $report_data[$dt]['le_wc']    = (int)$r['le_wc'];
            $report_data[$dt]['pag_art']   = (int)$r['pag_articles'];
            $report_data[$dt]['pag_pages'] = (int)$r['pag_pages'];
        }
    }
}

// Calculate Grand Totals and Weekly Totals
$gt_ce_art = 0; $gt_ce_wc = 0;
$gt_le_art = 0; $gt_le_wc = 0;
$gt_pag_art = 0; $gt_pag_pages = 0;
$weekly_data = [];

for ($d = 1; $d <= $days_in_month; $d++) {
    $cur_d = sprintf('%04d-%02d-%02d', $selected_year, $selected_month, $d);
    $week_num = 'W' . date('W', strtotime($cur_d));

    if (!isset($weekly_data[$week_num])) {
        $weekly_data[$week_num] = [
            'ce_art' => 0, 'ce_wc' => 0,
            'le_art' => 0, 'le_wc' => 0,
            'pag_art' => 0, 'pag_pages' => 0
        ];
    }

    $c = $report_data[$cur_d];
    $weekly_data[$week_num]['ce_art'] += $c['ce_art'];
    $weekly_data[$week_num]['ce_wc']  += $c['ce_wc'];
    $weekly_data[$week_num]['le_art'] += $c['le_art'];
    $weekly_data[$week_num]['le_wc']  += $c['le_wc'];
    $weekly_data[$week_num]['pag_art'] += $c['pag_art'];
    $weekly_data[$week_num]['pag_pages'] += $c['pag_pages'];

    $gt_ce_art += $c['ce_art'];
    $gt_ce_wc  += $c['ce_wc'];
    $gt_le_art += $c['le_art'];
    $gt_le_wc  += $c['le_wc'];
    $gt_pag_art += $c['pag_art'];
    $gt_pag_pages += $c['pag_pages'];
}

$grand_total_articles = $gt_ce_art + $gt_le_art + $gt_pag_art;

require 'header.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>WK Report</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet" />
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet" />
    <style>
        * { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; }
        body { background-color: #f5f7fa; }

        /* Dashboard Cards */
        .dashboard-card {
            border-radius: 10px;
            padding: 15px;
            margin-bottom: 15px;
            color: white;
            text-align: center;
            box-shadow: 0 4px 6px rgba(0,0,0,0.08);
        }
        .card-purple { background: linear-gradient(45deg, #667eea, #764ba2); }
        .card-green  { background: linear-gradient(45deg, #11998e, #38ef7d); }
        .card-orange { background: linear-gradient(45deg, #ff8c00, #ff2d00); }
        .card-red    { background: linear-gradient(45deg, #f46b45, #eea849); }

        .dashboard-card .count { font-size: 2rem; font-weight: bold; margin-bottom: 4px; }
        .dashboard-card .label { font-size: 0.88rem; opacity: 0.95; font-weight: 500; }

        /* Table Format Matching 2nd Image with Grey Header */
        .wk-report-table {
            border-collapse: collapse;
            width: 100%;
            background: white;
            font-size: 0.88rem;
        }
        .wk-report-table th, .wk-report-table td {
            border: 1px solid #5a6268;
            padding: 5px 8px;
            vertical-align: middle;
        }

        /* Top Group Header (GREY) */
        .wk-report-table thead tr.group-header th {
            background-color: #707981 !important;
            color: white !important;
            font-weight: bold;
            font-size: 0.92rem;
            text-transform: uppercase;
            text-align: center;
            border: 1px solid #5a6268 !important;
        }

        /* Sub Header (GREY) */
        .wk-report-table thead tr.sub-header th {
            background-color: #5a6268 !important;
            color: white !important;
            font-weight: bold;
            font-size: 0.85rem;
            text-align: center;
            border: 1px solid #4e555b !important;
        }

        /* Green target box from 2nd image */
        .badge-target {
            background-color: #388e3c !important;
            color: white !important;
            font-weight: bold;
            font-size: 1.15rem;
            text-align: center;
            padding: 6px;
        }

        .row-weekend {
            background-color: #e9ecef !important;
        }

        .weekly-row {
            background-color: #cfd4da !important;
            font-weight: bold;
            color: #212529;
        }

        .gt-row {
            background-color: #000000 !important;
            color: #ffffff !important;
            font-weight: bold;
            font-size: 0.95rem;
        }
        .gt-row td {
            border: 1px solid #212529 !important;
            padding: 8px 10px;
        }
    </style>
</head>
<body>
<div class="container-fluid px-4 py-3">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h2 class="fw-bold mb-0" style="color: #2c3e50;">
            <i class="fas fa-file-invoice me-2" style="color: #667eea;"></i>WK Report
        </h2>
        <button class="btn btn-primary btn-sm px-3" data-bs-toggle="modal" data-bs-target="#addReportModal">
            <i class="fas fa-plus me-1"></i> Add WK Report
        </button>
    </div>

    <!-- 4 Dashboard Cards -->
    <div class="row mb-4">
        <div class="col-md-3">
            <div class="dashboard-card card-purple">
                <div class="count"><?= number_format($grand_total_articles) ?></div>
                <div class="label">Total WK Articles Output</div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="dashboard-card card-green">
                <div class="count"><?= number_format($gt_ce_wc) ?></div>
                <div class="label">WK (CE) Total Word Count</div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="dashboard-card card-orange">
                <div class="count"><?= number_format($gt_le_wc) ?></div>
                <div class="label">WK (LE) Total Word Count</div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="dashboard-card card-red">
                <div class="count"><?= number_format($gt_pag_pages) ?></div>
                <div class="label">WK (PAG) Total Pages</div>
            </div>
        </div>
    </div>

    <!-- Filter Form -->
    <div class="card shadow-sm border-0 mb-4">
        <div class="card-body py-2">
            <form method="get" class="row g-2 align-items-center">
                <div class="col-auto">
                    <label class="form-label small fw-bold mb-0">Select Month:</label>
                </div>
                <div class="col-auto">
                    <select name="month" class="form-select form-select-sm">
                        <?php for ($m = 1; $m <= 12; $m++): ?>
                            <option value="<?= $m ?>" <?= ($selected_month == $m) ? 'selected' : '' ?>>
                                <?= date('F', mktime(0, 0, 0, $m, 10)) ?>
                            </option>
                        <?php endfor; ?>
                    </select>
                </div>

                <div class="col-auto">
                    <label class="form-label small fw-bold mb-0">Year:</label>
                </div>
                <div class="col-auto">
                    <select name="year" class="form-select form-select-sm">
                        <?php for ($y = date('Y') - 1; $y <= date('Y') + 2; $y++): ?>
                            <option value="<?= $y ?>" <?= ($selected_year == $y) ? 'selected' : '' ?>><?= $y ?></option>
                        <?php endfor; ?>
                    </select>
                </div>

                <div class="col-auto">
                    <button type="submit" class="btn btn-primary btn-sm px-3">
                        <i class="fas fa-filter me-1"></i> Apply
                    </button>
                    <a href="wk_report.php" class="btn btn-outline-secondary btn-sm ms-1">
                        <i class="fas fa-undo me-1"></i> Reset
                    </a>
                </div>
            </form>
        </div>
    </div>

    <!-- Main Table: Exactly Matching 2nd Image with Grey Header -->
    <div class="card shadow-sm border-0 overflow-hidden mb-4">
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="wk-report-table" id="wkReportTable">
                    <thead>
                        <!-- Group Header (Grey) -->
                        <tr class="group-header">
                            <th class="badge-target" style="width: 120px;">
                                <?= number_format($grand_total_articles) ?>
                            </th>
                            <th colspan="2">WK (CE)</th>
                            <th colspan="2">WK (LE)</th>
                            <th colspan="2">WK (PAG)</th>
                        </tr>
                        <!-- Sub Header (Grey) -->
                        <tr class="sub-header">
                            <th>Date</th>
                            <th style="width: 130px;">Articles</th>
                            <th style="width: 150px;">WC</th>
                            <th style="width: 130px;">Articles</th>
                            <th style="width: 150px;">WC</th>
                            <th style="width: 130px;">Articles</th>
                            <th style="width: 150px;">Pages</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php for ($d = 1; $d <= $days_in_month; $d++): 
                            $date_str = sprintf('%04d-%02d-%02d', $selected_year, $selected_month, $d);
                            $day_of_week = date('N', strtotime($date_str)); // 7 = Sunday
                            $is_weekend = ($day_of_week == 7);
                            $row = $report_data[$date_str];

                            $ce_art = $row['ce_art'];
                            $ce_wc  = $row['ce_wc'];
                            $le_art = $row['le_art'];
                            $le_wc  = $row['le_wc'];
                            $pag_art = $row['pag_art'];
                            $pag_pages = $row['pag_pages'];
                        ?>
                            <tr class="<?= $is_weekend ? 'row-weekend' : '' ?>">
                                <!-- Date Column (e.g. 1-Oct, 2-Oct) -->
                                <td class="text-center fw-semibold" style="background-color: <?= $is_weekend ? '#e9ecef' : '#f8f9fa' ?>;">
                                    <?= $d . '-' . date('M', strtotime($date_str)) ?>
                                </td>

                                <!-- WK (CE) Articles -->
                                <td class="text-end">
                                    <?= ($ce_art > 0) ? number_format($ce_art) : '' ?>
                                </td>

                                <!-- WK (CE) WC -->
                                <td class="text-end">
                                    <?= ($ce_wc > 0) ? number_format($ce_wc) : '' ?>
                                </td>

                                <!-- WK (LE) Articles -->
                                <td class="text-end">
                                    <?= ($le_art > 0) ? number_format($le_art) : '' ?>
                                </td>

                                <!-- WK (LE) WC -->
                                <td class="text-end">
                                    <?= ($le_wc > 0) ? number_format($le_wc) : '' ?>
                                </td>

                                <!-- WK (PAG) Articles -->
                                <td class="text-end">
                                    <?= ($pag_art > 0) ? number_format($pag_art) : '' ?>
                                </td>

                                <!-- WK (PAG) Pages -->
                                <td class="text-end">
                                    <?= ($pag_pages > 0) ? number_format($pag_pages) : '' ?>
                                </td>
                            </tr>
                        <?php endfor; ?>

                        <!-- Weekly Totals (W27, W28, W29...) -->
                        <?php foreach ($weekly_data as $w_label => $w_vals): ?>
                            <tr class="weekly-row">
                                <td class="text-center"><?= $w_label ?></td>
                                <td class="text-end"><?= ($w_vals['ce_art'] > 0) ? number_format($w_vals['ce_art']) : '0' ?></td>
                                <td class="text-end"><?= ($w_vals['ce_wc'] > 0) ? number_format($w_vals['ce_wc']) : '0' ?></td>
                                <td class="text-end"><?= ($w_vals['le_art'] > 0) ? number_format($w_vals['le_art']) : '0' ?></td>
                                <td class="text-end"><?= ($w_vals['le_wc'] > 0) ? number_format($w_vals['le_wc']) : '0' ?></td>
                                <td class="text-end"><?= ($w_vals['pag_art'] > 0) ? number_format($w_vals['pag_art']) : '0' ?></td>
                                <td class="text-end"><?= ($w_vals['pag_pages'] > 0) ? number_format($w_vals['pag_pages']) : '0' ?></td>
                            </tr>
                        <?php endforeach; ?>

                        <!-- Grand Total (GT) Black Bar -->
                        <tr class="gt-row">
                            <td class="text-center">GT</td>
                            <td class="text-end"><?= number_format($gt_ce_art) ?></td>
                            <td class="text-end"><?= number_format($gt_ce_wc) ?></td>
                            <td class="text-end"><?= number_format($gt_le_art) ?></td>
                            <td class="text-end"><?= number_format($gt_le_wc) ?></td>
                            <td class="text-end"><?= number_format($gt_pag_art) ?></td>
                            <td class="text-end"><?= number_format($gt_pag_pages) ?></td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<!-- Modal to Add New WK Report Entry -->
<div class="modal fade" id="addReportModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <form method="post" action="wk_report.php">
                <input type="hidden" name="action" value="add_report">
                <div class="modal-header">
                    <h5 class="modal-title fw-bold" style="color: #1e293b;">
                        <i class="fas fa-plus-circle text-primary me-2"></i>Add WK Report Entry
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body row g-3">
                    <div class="col-md-12">
                        <label class="form-label small fw-semibold">Report Date <span class="text-danger">*</span></label>
                        <input type="date" name="report_date" class="form-control form-control-sm" value="<?= date('Y-m-d') ?>" required>
                    </div>
                    
                    <!-- WK (CE) Group -->
                    <div class="col-12"><h6 class="fw-bold text-primary mb-0 border-bottom pb-1"><i class="fas fa-file-alt me-1"></i> WK (CE)</h6></div>
                    <div class="col-md-6">
                        <label class="form-label small fw-semibold">CE Articles</label>
                        <input type="number" name="ce_articles" class="form-control form-control-sm" value="0" min="0">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label small fw-semibold">CE Word Count (WC)</label>
                        <input type="number" name="ce_wc" class="form-control form-control-sm" value="0" min="0">
                    </div>

                    <!-- WK (LE) Group -->
                    <div class="col-12"><h6 class="fw-bold text-warning mb-0 border-bottom pb-1"><i class="fas fa-layer-group me-1"></i> WK (LE)</h6></div>
                    <div class="col-md-6">
                        <label class="form-label small fw-semibold">LE Articles</label>
                        <input type="number" name="le_articles" class="form-control form-control-sm" value="0" min="0">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label small fw-semibold">LE Word Count (WC)</label>
                        <input type="number" name="le_wc" class="form-control form-control-sm" value="0" min="0">
                    </div>

                    <!-- WK (PAG) Group -->
                    <div class="col-12"><h6 class="fw-bold text-danger mb-0 border-bottom pb-1"><i class="fas fa-file-invoice me-1"></i> WK (PAG)</h6></div>
                    <div class="col-md-6">
                        <label class="form-label small fw-semibold">PAG Articles</label>
                        <input type="number" name="pag_articles" class="form-control form-control-sm" value="0" min="0">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label small fw-semibold">PAG Pages</label>
                        <input type="number" name="pag_pages" class="form-control form-control-sm" value="0" min="0">
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary btn-sm px-3">
                        <i class="fas fa-save me-1"></i> Save Report Entry
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function exportTableToExcel(tableID, filename = '') {
    var downloadLink;
    var dataType = 'application/vnd.ms-excel';
    var tableSelect = document.getElementById(tableID);
    var tableHTML = tableSelect.outerHTML.replace(/ /g, '%20');
    
    filename = filename ? filename + '.xls' : 'WK_Report.xls';
    downloadLink = document.createElement("a");
    document.body.appendChild(downloadLink);
    
    if (navigator.msSaveOrOpenBlob) {
        var blob = new Blob(['\ufeff', tableHTML], { type: dataType });
        navigator.msSaveOrOpenBlob(blob, filename);
    } else {
        downloadLink.href = 'data:' + dataType + ', ' + tableHTML;
        downloadLink.download = filename;
        downloadLink.click();
    }
    document.body.removeChild(downloadLink);
}
</script>
</body>
</html>