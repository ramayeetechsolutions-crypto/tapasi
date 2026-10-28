<?php
ob_start();
require 'db.php';
if (session_status() === PHP_SESSION_NONE) { 
    session_start(); 
}

// Check if user is logged in
if (!isset($_SESSION['uid'])) {
    header("Location: login.php");
    exit;
}

// Helper functions
function h($s) { 
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); 
}

function formatDate($d, $format = 'd-m-Y') {
    if (empty($d) || $d === '0000-00-00' || $d === '0000-00-00 00:00:00') return '—';
    return date($format, strtotime($d));
}

function formatNumber($num) {
    return number_format($num, 0);
}

function getMonthName($month) {
    $months = [
        1 => 'January', 2 => 'February', 3 => 'March', 4 => 'April',
        5 => 'May', 6 => 'June', 7 => 'July', 8 => 'August',
        9 => 'September', 10 => 'October', 11 => 'November', 12 => 'December'
    ];
    return $months[$month] ?? 'Unknown';
}

// Get current year
$current_year = date('Y');
$selected_year = $_GET['year'] ?? $current_year;
$selected_year = is_numeric($selected_year) ? (int)$selected_year : $current_year;

// Get filters
$client_id = (int)($_GET['client_id'] ?? 0);
$export_type = $_GET['export'] ?? '';

// Get all clients for dropdown
$clients_result = $mysqli->query("SELECT client_id, client_name, company_name FROM client_master WHERE status = 'Active' ORDER BY client_name");
$clients = [];
if ($clients_result) {
    while ($row = $clients_result->fetch_assoc()) {
        $clients[] = $row;
    }
}

// Get years available in data
$years_result = $mysqli->query("SELECT DISTINCT YEAR(job_date) as year FROM jobs WHERE job_date IS NOT NULL UNION SELECT DISTINCT YEAR(date) as year FROM pag WHERE date IS NOT NULL ORDER BY year DESC");
$years = [];
if ($years_result) {
    while ($row = $years_result->fetch_assoc()) {
        $years[] = $row['year'];
    }
}
if (empty($years)) {
    $years[] = $current_year;
}

// Initialize month data structure
$months_data = [];
for ($month = 1; $month <= 12; $month++) {
    $months_data[$month] = [
        'name' => getMonthName($month),
        'total_articles' => 0,
        'total_pages' => 0,
        'total_images' => 0,
        'total_tables' => 0,
        'clients' => []
    ];
}

// Get client data for the selected year
$client_where = $client_id > 0 ? " AND cm.client_id = ?" : "";
$params = [$selected_year];
$types = 'i';

if ($client_id > 0) {
    $params[] = $client_id;
    $types .= 'i';
}

// Query for monthly data
$monthly_sql = "
    SELECT 
        cm.client_id,
        cm.client_name,
        cm.company_name,
        cm.contact_person,
        cm.email,
        cm.phone,
        MONTH(j.job_date) as month,
        COUNT(DISTINCT j.job_id) as monthly_articles,
        COALESCE(SUM(p.pages), 0) as monthly_pages,
        COALESCE(SUM(p.images), 0) as monthly_images,
        COALESCE(SUM(p.tables_count), 0) as monthly_tables
    FROM client_master cm
    LEFT JOIN jobs j ON cm.client_id = j.client_id AND YEAR(j.job_date) = ?
    LEFT JOIN pag p ON j.job_id = p.job_id AND YEAR(p.date) = ?
    WHERE cm.status = 'Active'
    $client_where
    GROUP BY cm.client_id, MONTH(j.job_date)
    HAVING monthly_articles > 0 OR monthly_pages > 0
    ORDER BY cm.client_name, month
";

$stmt = $mysqli->prepare($monthly_sql);
if ($stmt) {
    // For monthly query, we need year parameter twice
    $monthly_params = [$selected_year, $selected_year];
    if ($client_id > 0) {
        $monthly_params[] = $client_id;
    }
    $stmt->bind_param(str_repeat('i', count($monthly_params)), ...$monthly_params);
    $stmt->execute();
    $monthly_stats = $stmt->get_result();
    
    if ($monthly_stats && $monthly_stats->num_rows > 0) {
        while ($row = $monthly_stats->fetch_assoc()) {
            $month = (int)$row['month'];
            $client_id_val = $row['client_id'];
            
            if (!isset($months_data[$month]['clients'][$client_id_val])) {
                $months_data[$month]['clients'][$client_id_val] = [
                    'client_name' => $row['client_name'],
                    'company_name' => $row['company_name'],
                    'articles' => 0,
                    'pages' => 0,
                    'images' => 0,
                    'tables' => 0
                ];
            }
            
            $months_data[$month]['clients'][$client_id_val]['articles'] += $row['monthly_articles'];
            $months_data[$month]['clients'][$client_id_val]['pages'] += $row['monthly_pages'];
            $months_data[$month]['clients'][$client_id_val]['images'] += $row['monthly_images'];
            $months_data[$month]['clients'][$client_id_val]['tables'] += $row['monthly_tables'];
            
            $months_data[$month]['total_articles'] += $row['monthly_articles'];
            $months_data[$month]['total_pages'] += $row['monthly_pages'];
            $months_data[$month]['total_images'] += $row['monthly_images'];
            $months_data[$month]['total_tables'] += $row['monthly_tables'];
        }
    }
}

// Calculate yearly totals
$yearly_totals = [
    'total_articles' => 0,
    'total_pages' => 0,
    'total_images' => 0,
    'total_tables' => 0,
    'total_clients' => []
];

foreach ($months_data as $month) {
    $yearly_totals['total_articles'] += $month['total_articles'];
    $yearly_totals['total_pages'] += $month['total_pages'];
    $yearly_totals['total_images'] += $month['total_images'];
    $yearly_totals['total_tables'] += $month['total_tables'];
    
    foreach ($month['clients'] as $client_id => $client) {
        $yearly_totals['total_clients'][$client_id] = $client['client_name'];
    }
}

// Handle Excel Export
if ($export_type === 'excel') {
    header('Content-Type: application/vnd.ms-excel');
    header('Content-Disposition: attachment; filename="client_articles_monthly_report_' . $selected_year . '.xls"');
    
    echo "<table border='1'>";
    echo "<tr><th colspan='15' style='background-color:#2c3e50;color:white;padding:10px;font-size:16px;'>Client Articles & Pages Monthly Report - $selected_year</th></tr>";
    echo "<tr><td colspan='15'><strong>Generated:</strong> " . date('d-m-Y H:i:s') . "</td></tr>";
    echo "<tr><td colspan='15'>&nbsp;</td></tr>";
    
    // Month headers
    echo "<tr style='background-color:#34495e;color:white;'>";
    echo "<th>Client Name</th>";
    for ($month = 1; $month <= 12; $month++) {
        echo "<th>" . substr($months_data[$month]['name'], 0, 3) . "</th>";
    }
    echo "<th>Year Total</th>";
    echo "</tr>";
    
    // Get unique clients across all months
    $all_clients = [];
    foreach ($months_data as $month) {
        foreach ($month['clients'] as $client_id => $client) {
            if (!isset($all_clients[$client_id])) {
                $all_clients[$client_id] = [
                    'name' => $client['client_name'],
                    'monthly_articles' => array_fill(1, 12, 0),
                    'year_total' => 0
                ];
            }
        }
    }
    
    // Populate client monthly data
    foreach ($months_data as $month_num => $month) {
        foreach ($month['clients'] as $client_id => $client) {
            $all_clients[$client_id]['monthly_articles'][$month_num] = $client['articles'];
            $all_clients[$client_id]['year_total'] += $client['articles'];
        }
    }
    
    // Output client rows
    foreach ($all_clients as $client) {
        echo "<tr>";
        echo "<td>" . h($client['name']) . "</td>";
        for ($month = 1; $month <= 12; $month++) {
            $value = $client['monthly_articles'][$month];
            echo "<td align='center'>" . ($value > 0 ? formatNumber($value) : '-') . "</td>";
        }
        echo "<td align='center'><strong>" . formatNumber($client['year_total']) . "</strong></td>";
        echo "</tr>";
    }
    
    // Monthly totals row
    echo "<tr style='background-color:#27ae60;color:white;font-weight:bold;'>";
    echo "<td>MONTHLY TOTALS</td>";
    for ($month = 1; $month <= 12; $month++) {
        echo "<td align='center'>" . formatNumber($months_data[$month]['total_articles']) . "</td>";
    }
    echo "<td align='center'>" . formatNumber($yearly_totals['total_articles']) . "</td>";
    echo "</tr>";
    
    // Monthly pages row
    echo "<tr style='background-color:#e74c3c;color:white;font-weight:bold;'>";
    echo "<td>PAGES</td>";
    for ($month = 1; $month <= 12; $month++) {
        echo "<td align='center'>" . formatNumber($months_data[$month]['total_pages']) . "</td>";
    }
    echo "<td align='center'>" . formatNumber($yearly_totals['total_pages']) . "</td>";
    echo "</tr>";
    
    echo "</table>";
    exit;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Client Articles Monthly Report - <?php echo $selected_year; ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/dataTables.bootstrap5.min.css">
    <style>
        body {
            background-color: #f8f9fa;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }
        .main-content {
            padding: 20px;
            max-width: 1600px;
            margin: 0 auto;
        }
        .header-card {
            /* background: linear-gradient(135deg, #2c3e50 0%, #4a6491 100%); */
            background-color: #707981 !important;
            color: white;
            border-radius: 10px;
            padding: 25px;
            margin-bottom: 20px;
        }
        .stat-card {
            background: white;
            border-radius: 8px;
            padding: 20px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            border-left: 4px solid #3498db;
            margin-bottom: 15px;
        }
        .stat-card.articles { border-left-color: #2ecc71; }
        .stat-card.pages { border-left-color: #e74c3c; }
        .stat-card.images { border-left-color: #f39c12; }
        .stat-card.tables { border-left-color: #9b59b6; }
        .stat-number {
            font-size: 28px;
            font-weight: bold;
            color: #2c3e50;
        }
        .stat-label {
            font-size: 14px;
            color: #7f8c8d;
            text-transform: uppercase;
            letter-spacing: 1px;
        }
        .table th {
            /* background-color: #34495e; */
             background-color: #707981 !important;
            color: white;
            border: none;
        }
        .table td {
            vertical-align: middle;
        }
        .month-card {
            background: white;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            margin-bottom: 15px;
            overflow: hidden;
        }
        .month-header {
            /* background: linear-gradient(135deg, #3498db 0%, #2980b9 100%); */
            background-color: #707981 !important;
            color: white;
            padding: 15px;
            font-weight: bold;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .month-content {
            padding: 15px;
        }
        .filter-card {
            background-color: white;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        .export-btn {
            background: linear-gradient(135deg, #27ae60 0%, #2ecc71 100%);
            color: white;
            border: none;
        }
        .export-btn:hover {
            background: linear-gradient(135deg, #219653 0%, #27ae60 100%);
            color: white;
        }
        .badge-articles { background-color: #2ecc71; }
        .badge-pages { background-color: #e74c3c; }
        .badge-images { background-color: #f39c12; }
        .badge-tables { background-color: #9b59b6; }
        .month-badge {
            /* background-color: #3498db; */
            background-color: rgba(255,255,255,0.2) !important;
            color: white;
            padding: 3px 8px;
            border-radius: 4px;
            font-size: 12px;
            font-weight: bold;
        }
        .client-item {
            padding: 10px 15px;
            border-bottom: 1px solid #eee;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .client-item:last-child {
            border-bottom: none;
        }
        .month-total {
            background-color: #f8f9fa;
            padding: 10px 15px;
            font-weight: bold;
            border-top: 2px solid #dee2e6;
        }
        .month-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(350px, 1fr));
            gap: 20px;
        }
        @media (max-width: 768px) {
            .month-grid {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>
    <?php require 'header.php'; ?>
    
    <div class="main-content">
        <!-- Header -->
        <div class="header-card">
            <div class="row align-items-center">
                <div class="col-md-8">
                    <h2><i class="fas fa-chart-bar me-2"></i>Client Articles Monthly Report - <?php echo $selected_year; ?></h2>
                    <p class="mb-0">Month-wise analysis of articles and pages by client</p>
                </div>
                <div class="col-md-4 text-end">
                    <div class="btn-group">
                        <a href="?export=excel&year=<?= $selected_year ?>&client_id=<?= $client_id ?>" 
                           class="btn export-btn me-2">
                            <i class="fas fa-file-excel me-1"></i> Export Excel
                        </a>
                        <button onclick="window.print()" class="btn btn-primary">
                            <i class="fas fa-print me-1"></i> Print
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <!-- Statistics Cards -->
        <div class="row mb-4">
            <div class="col-md-3">
                <div class="stat-card">
                    <div class="stat-number"><?= formatNumber(count($yearly_totals['total_clients'])) ?></div>
                    <div class="stat-label">Active Clients</div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="stat-card articles">
                    <div class="stat-number"><?= formatNumber($yearly_totals['total_articles']) ?></div>
                    <div class="stat-label">Total Articles</div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="stat-card pages">
                    <div class="stat-number"><?= formatNumber($yearly_totals['total_pages']) ?></div>
                    <div class="stat-label">Total Pages</div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="stat-card images">
                    <div class="stat-number"><?= formatNumber($yearly_totals['total_images'] + $yearly_totals['total_tables']) ?></div>
                    <div class="stat-label">Images + Tables</div>
                </div>
            </div>
        </div>

        <!-- Filter Card -->
        <div class="card filter-card mb-4">
            <div class="card-body">
                <form method="get" class="row g-3 align-items-center">
                    <div class="col-md-4">
                        <label class="form-label mb-1"><i class="fas fa-calendar-alt me-1"></i> Year</label>
                        <select name="year" class="form-select form-select-sm">
                            <?php foreach ($years as $year): ?>
                                <option value="<?= $year ?>" <?= $selected_year == $year ? 'selected' : '' ?>>
                                    <?= $year ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="col-md-6">
                        <label class="form-label mb-1"><i class="fas fa-users me-1"></i> Client</label>
                        <select name="client_id" class="form-select form-select-sm">
                            <option value="0">All Clients</option>
                            <?php foreach ($clients as $client): ?>
                                <option value="<?= $client['client_id'] ?>" <?= $client_id == $client['client_id'] ? 'selected' : '' ?>>
                                    <?= h($client['client_name']) ?> (<?= h($client['company_name']) ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="col-md-2">
                        <label class="form-label mb-1">&nbsp;</label>
                        <div class="d-flex">
                            <button type="submit" class="btn btn-primary btn-sm me-2">
                                <i class="fas fa-filter me-1"></i> Filter
                            </button>
                            <a href="client_article.php" class="btn btn-outline-secondary btn-sm">
                                <i class="fas fa-times me-1"></i> Clear
                            </a>
                        </div>
                    </div>
                </form>
            </div>
        </div>

        <!-- Monthly Grid View -->
        <div class="month-grid">
            <?php foreach ($months_data as $month_num => $month): ?>
            <div class="month-card">
                <div class="month-header">
                    <span><?= $month['name'] ?></span>
                    <span class="month-badge">
                        Articles: <?= formatNumber($month['total_articles']) ?> | 
                        Pages: <?= formatNumber($month['total_pages']) ?>
                    </span>
                </div>
                <div class="month-content">
                    <?php if (!empty($month['clients'])): ?>
                        <?php foreach ($month['clients'] as $client): ?>
                        <div class="client-item">
                            <div>
                                <div class="fw-bold"><?= h($client['client_name']) ?></div>
                                <small class="text-muted"><?= h($client['company_name']) ?></small>
                            </div>
                            <div class="text-end">
                                <div>
                                    <span class="badge badge-articles me-1"><?= formatNumber($client['articles']) ?> A</span>
                                    <span class="badge badge-pages"><?= formatNumber($client['pages']) ?> P</span>
                                </div>
                                <small class="text-muted">
                                    <?php if ($client['images'] > 0): ?>
                                    <i class="fas fa-image"></i> <?= $client['images'] ?>
                                    <?php endif; ?>
                                    <?php if ($client['tables'] > 0): ?>
                                    <i class="fas fa-table ms-2"></i> <?= $client['tables'] ?>
                                    <?php endif; ?>
                                </small>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div class="text-center py-3 text-muted">
                            <i class="fas fa-inbox fa-2x mb-2"></i>
                            <p>No data for this month</p>
                        </div>
                    <?php endif; ?>
                    
                    <?php if ($month['total_articles'] > 0): ?>
                    <div class="month-total">
                        <div class="row">
                            <div class="col">
                                <small>Total Articles:</small>
                                <div class="fw-bold"><?= formatNumber($month['total_articles']) ?></div>
                            </div>
                            <div class="col">
                                <small>Total Pages:</small>
                                <div class="fw-bold"><?= formatNumber($month['total_pages']) ?></div>
                            </div>
                            <div class="col">
                                <small>Clients:</small>
                                <div class="fw-bold"><?= count($month['clients']) ?></div>
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
            <?php endforeach; ?>
        </div>

        <!-- Yearly Summary Table -->
        <div class="card mt-4">
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Month</th>
                                <th class="text-center">Clients</th>
                                <th class="text-center">Articles</th>
                                <th class="text-center">Pages</th>
                                <th class="text-center">Images</th>
                                <th class="text-center">Tables</th>
                                <th class="text-center">Avg Pages/Article</th>
                                <th class="text-center">% of Year</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($months_data as $month_num => $month): ?>
                            <?php
                                $avg_pages = $month['total_articles'] > 0 ? $month['total_pages'] / $month['total_articles'] : 0;
                                $percent_of_year = $yearly_totals['total_articles'] > 0 ? 
                                    ($month['total_articles'] / $yearly_totals['total_articles']) * 100 : 0;
                            ?>
                            <tr>
                                <td class="fw-bold"><?= $month['name'] ?></td>
                                <td class="text-center">
                                    <span class="badge bg-primary rounded-pill"><?= count($month['clients']) ?></span>
                                </td>
                                <td class="text-center fw-bold" style="color: #2ecc71;">
                                    <?= formatNumber($month['total_articles']) ?>
                                </td>
                                <td class="text-center fw-bold" style="color: #e74c3c;">
                                    <?= formatNumber($month['total_pages']) ?>
                                </td>
                                <td class="text-center">
                                    <span class="badge badge-images"><?= formatNumber($month['total_images']) ?></span>
                                </td>
                                <td class="text-center">
                                    <span class="badge badge-tables"><?= formatNumber($month['total_tables']) ?></span>
                                </td>
                                <td class="text-center">
                                    <span class="badge bg-secondary"><?= number_format($avg_pages, 1) ?></span>
                                </td>
                                <td class="text-center">
                                    <div class="progress" style="height: 10px;">
                                        <div class="progress-bar bg-success" 
                                             role="progressbar" 
                                             style="width: <?= $percent_of_year ?>%;" 
                                             aria-valuenow="<?= $percent_of_year ?>" 
                                             aria-valuemin="0" 
                                             aria-valuemax="100"></div>
                                    </div>
                                    <small><?= number_format($percent_of_year, 1) ?>%</small>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                            
                            <!-- Yearly Totals -->
                            <tr style="background-color: #f8f9fa; font-weight: bold;">
                                <td>YEAR <?= $selected_year ?> TOTAL</td>
                                <td class="text-center">
                                    <span class="badge bg-dark rounded-pill"><?= count($yearly_totals['total_clients']) ?></span>
                                </td>
                                <td class="text-center" style="color: #2ecc71;">
                                    <?= formatNumber($yearly_totals['total_articles']) ?>
                                </td>
                                <td class="text-center" style="color: #e74c3c;">
                                    <?= formatNumber($yearly_totals['total_pages']) ?>
                                </td>
                                <td class="text-center"><?= formatNumber($yearly_totals['total_images']) ?></td>
                                <td class="text-center"><?= formatNumber($yearly_totals['total_tables']) ?></td>
                                <td class="text-center">
                                    <?php
                                    $yearly_avg = $yearly_totals['total_articles'] > 0 ? 
                                        $yearly_totals['total_pages'] / $yearly_totals['total_articles'] : 0;
                                    echo number_format($yearly_avg, 1);
                                    ?>
                                </td>
                                <td class="text-center">100%</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <script src="https://code.jquery.com/jquery-3.7.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        $(document).ready(function() {
            // Auto-submit form on dropdown change
            $('select[name="year"], select[name="client_id"]').change(function() {
                $(this).closest('form').submit();
            });
        });
    </script>
</body>
</html>