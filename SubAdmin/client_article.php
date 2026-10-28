<?php
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

// Get filters
$from_date = $_GET['from_date'] ?? date('Y-m-01');
$to_date = $_GET['to_date'] ?? date('Y-m-d');
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

// Debug: Let's first check the table structure
// $check_table = $mysqli->query("DESCRIBE jobs");
// echo "<pre>";
// while($row = $check_table->fetch_assoc()) {
//     print_r($row);
// }
// echo "</pre>";
// exit;

// Try a simpler query first to test
$test_sql = "
    SELECT 
        cm.client_id,
        cm.client_name,
        cm.company_name,
        cm.contact_person,
        cm.email,
        cm.phone,
        COUNT(DISTINCT j.job_id) as total_articles,
        COALESCE(SUM(p.pages), 0) as total_pages,
        COALESCE(SUM(p.images), 0) as total_images,
        COALESCE(SUM(p.tables_count), 0) as total_tables
    FROM client_master cm
    LEFT JOIN jobs j ON cm.client_id = j.client_id
    LEFT JOIN pag p ON j.job_id = p.job_id
    WHERE cm.status = 'Active'
    AND (j.job_date IS NULL OR (j.job_date BETWEEN ? AND ?))
    " . ($client_id > 0 ? " AND cm.client_id = ?" : "") . "
    GROUP BY cm.client_id, cm.client_name, cm.company_name
    HAVING total_articles > 0 OR total_pages > 0
    ORDER BY total_articles DESC, cm.client_name
";

// Build parameters
$params = [];
$types = '';

if ($client_id > 0) {
    $params = [$from_date, $to_date . ' 23:59:59', $client_id];
    $types = 'ssi';
} else {
    $params = [$from_date, $to_date . ' 23:59:59'];
    $types = 'ss';
}

$stmt = $mysqli->prepare($test_sql);
if ($stmt) {
    if (!empty($params)) {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $client_stats = $stmt->get_result();
} else {
    // If still error, try without date filter
    $test_sql2 = "
        SELECT 
            cm.client_id,
            cm.client_name,
            cm.company_name,
            cm.contact_person,
            cm.email,
            cm.phone,
            COUNT(DISTINCT j.job_id) as total_articles,
            COALESCE(SUM(p.pages), 0) as total_pages,
            COALESCE(SUM(p.images), 0) as total_images,
            COALESCE(SUM(p.tables_count), 0) as total_tables
        FROM client_master cm
        LEFT JOIN jobs j ON cm.client_id = j.client_id
        LEFT JOIN pag p ON j.job_id = p.job_id
        WHERE cm.status = 'Active'
        " . ($client_id > 0 ? " AND cm.client_id = ?" : "") . "
        GROUP BY cm.client_id, cm.client_name, cm.company_name
        HAVING total_articles > 0 OR total_pages > 0
        ORDER BY total_articles DESC, cm.client_name
    ";
    
    $stmt2 = $mysqli->prepare($test_sql2);
    if ($stmt2) {
        if ($client_id > 0) {
            $stmt2->bind_param('i', $client_id);
        }
        $stmt2->execute();
        $client_stats = $stmt2->get_result();
    } else {
        die("Database Error: " . $mysqli->error);
    }
}

// Calculate totals
$grand_totals = [
    'total_clients' => 0,
    'total_articles' => 0,
    'total_pages' => 0,
    'total_images' => 0,
    'total_tables' => 0
];

// Store data for export
$export_data = [];
if ($client_stats && $client_stats->num_rows > 0) {
    while ($row = $client_stats->fetch_assoc()) {
        $export_data[] = $row;
        $grand_totals['total_clients']++;
        $grand_totals['total_articles'] += $row['total_articles'];
        $grand_totals['total_pages'] += $row['total_pages'];
        $grand_totals['total_images'] += $row['total_images'];
        $grand_totals['total_tables'] += $row['total_tables'];
    }
    // Reset pointer
    $client_stats->data_seek(0);
}

// Handle Excel Export
if ($export_type === 'excel') {
    header('Content-Type: application/vnd.ms-excel');
    header('Content-Disposition: attachment; filename="client_articles_report_' . date('Y-m-d') . '.xls"');
    
    echo "<table border='1'>";
    echo "<tr><th colspan='10' style='background-color:#2c3e50;color:white;padding:10px;font-size:16px;'>Client Articles & Pages Report</th></tr>";
    echo "<tr><td colspan='10'><strong>Period:</strong> " . formatDate($from_date) . " to " . formatDate($to_date) . "</td></tr>";
    echo "<tr><td colspan='10'><strong>Generated:</strong> " . date('d-m-Y H:i:s') . "</td></tr>";
    echo "<tr><td colspan='10'>&nbsp;</td></tr>";
    
    echo "<tr style='background-color:#34495e;color:white;'>";
    echo "<th>Sr.</th>";
    echo "<th>Client Name</th>";
    echo "<th>Company</th>";
    echo "<th>Contact Person</th>";
    echo "<th>Email</th>";
    echo "<th>Phone</th>";
    echo "<th>Articles</th>";
    echo "<th>Pages</th>";
    echo "<th>Images</th>";
    echo "<th>Tables</th>";
    echo "</tr>";
    
    $serial = 1;
    if (!empty($export_data)) {
        foreach ($export_data as $row) {
            echo "<tr>";
            echo "<td>" . $serial++ . "</td>";
            echo "<td>" . h($row['client_name']) . "</td>";
            echo "<td>" . h($row['company_name']) . "</td>";
            echo "<td>" . h($row['contact_person']) . "</td>";
            echo "<td>" . h($row['email']) . "</td>";
            echo "<td>" . h($row['phone']) . "</td>";
            echo "<td align='center'>" . formatNumber($row['total_articles']) . "</td>";
            echo "<td align='center'>" . formatNumber($row['total_pages']) . "</td>";
            echo "<td align='center'>" . formatNumber($row['total_images']) . "</td>";
            echo "<td align='center'>" . formatNumber($row['total_tables']) . "</td>";
            echo "</tr>";
        }
        
        // Grand Totals
        echo "<tr style='background-color:#27ae60;color:white;font-weight:bold;'>";
        echo "<td colspan='6' align='right'>GRAND TOTALS:</td>";
        echo "<td align='center'>" . formatNumber($grand_totals['total_articles']) . "</td>";
        echo "<td align='center'>" . formatNumber($grand_totals['total_pages']) . "</td>";
        echo "<td align='center'>" . formatNumber($grand_totals['total_images']) . "</td>";
        echo "<td align='center'>" . formatNumber($grand_totals['total_tables']) . "</td>";
        echo "</tr>";
        echo "<tr><td colspan='10' align='center'>Total Clients: " . formatNumber($grand_totals['total_clients']) . "</td></tr>";
    } else {
        echo "<tr><td colspan='10' align='center'>No data found for the selected period</td></tr>";
    }
    
    echo "</table>";
    exit;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Client Articles Report</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body {
            background-color: #f8f9fa;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }
        .main-content {
            padding: 20px;
            max-width: 1400px;
            margin: 0 auto;
        }
        .header-card {
            /* background: linear-gradient(135deg, #2c3e50 0%, #4a6491 100%); */
            background-color: #707981 !important;
            color: white;
            border-radius: 10px;
            padding: 20px;
            margin-bottom: 20px;
        }
        .stat-card {
            background: white;
            border-radius: 8px;
            padding: 15px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            border-left: 4px solid #3498db;
            margin-bottom: 15px;
        }
        .stat-card.articles { border-left-color: #2ecc71; }
        .stat-card.pages { border-left-color: #e74c3c; }
        .stat-card.images { border-left-color: #f39c12; }
        .stat-number {
            font-size: 24px;
            font-weight: bold;
            color: #2c3e50;
        }
        .stat-label {
            font-size: 13px;
            color: #7f8c8d;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        .table th {
            background-color: #2c3e50;
            color: white;
            border: none;
        }
        .table td {
            vertical-align: middle;
        }
        .filter-card {
            background-color: white;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            padding: 15px;
            margin-bottom: 20px;
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
    </style>
</head>
<body>
    <?php require 'header.php'; ?>
    
    <div class="main-content">
        <!-- Header -->
        <div class="header-card">
            <div class="row align-items-center">
                <div class="col-md-8">
                    <h2><i class="fas fa-chart-bar me-2"></i>Client Articles & Pages Report</h2>
                    <p class="mb-0">Client-wise article and page count analysis</p>
                </div>
                <div class="col-md-4 text-end">
                    <?php if (!empty($export_data)): ?>
                    <a href="?export=excel&from_date=<?= h($from_date) ?>&to_date=<?= h($to_date) ?>&client_id=<?= $client_id ?>" 
                       class="btn export-btn">
                        <i class="fas fa-file-excel me-1"></i> Export to Excel
                    </a>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Statistics Cards -->
        <div class="row mb-4">
            <div class="col-md-3">
                <div class="stat-card">
                    <div class="stat-number"><?= formatNumber($grand_totals['total_clients']) ?></div>
                    <div class="stat-label">Active Clients</div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="stat-card articles">
                    <div class="stat-number"><?= formatNumber($grand_totals['total_articles']) ?></div>
                    <div class="stat-label">Total Articles</div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="stat-card pages">
                    <div class="stat-number"><?= formatNumber($grand_totals['total_pages']) ?></div>
                    <div class="stat-label">Total Pages</div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="stat-card images">
                    <div class="stat-number"><?= formatNumber($grand_totals['total_images'] + $grand_totals['total_tables']) ?></div>
                    <div class="stat-label">Images + Tables</div>
                </div>
            </div>
        </div>

        <!-- Filter Card -->
        <div class="filter-card">
            <form method="get" class="row g-3 align-items-center">
                <div class="col-md-3">
                    <label class="form-label mb-1"><i class="fas fa-calendar me-1"></i> From Date</label>
                    <input type="date" name="from_date" class="form-control form-control-sm" value="<?= h($from_date) ?>">
                </div>
                
                <div class="col-md-3">
                    <label class="form-label mb-1"><i class="fas fa-calendar me-1"></i> To Date</label>
                    <input type="date" name="to_date" class="form-control form-control-sm" value="<?= h($to_date) ?>">
                </div>
                
                <div class="col-md-4">
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

        <!-- Report Table -->
        <div class="card">
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th width="50">S.No</th>
                                <th>Client Details</th>
                                <th width="100" class="text-center">Articles</th>
                                <th width="100" class="text-center">Pages</th>
                                <th width="100" class="text-center">Images</th>
                                <th width="100" class="text-center">Tables</th>
                                <th width="120">Avg Pages/Article</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if(!empty($export_data)): ?>
                                <?php 
                                $serial = 1;
                                foreach ($export_data as $row): 
                                    $avg_pages = $row['total_articles'] > 0 ? $row['total_pages'] / $row['total_articles'] : 0;
                                ?>
                                <tr>
                                    <td class="fw-bold"><?= $serial++ ?></td>
                                    <td>
                                        <div class="fw-bold"><?= h($row['client_name']) ?></div>
                                        <div class="small text-muted"><?= h($row['company_name']) ?></div>
                                        <div class="small">
                                            <i class="fas fa-user me-1"></i><?= h($row['contact_person']) ?><br>
                                            <i class="fas fa-envelope me-1"></i><?= h($row['email']) ?>
                                        </div>
                                    </td>
                                    <td class="text-center">
                                        <span class="badge badge-articles rounded-pill px-3 py-2"><?= formatNumber($row['total_articles']) ?></span>
                                    </td>
                                    <td class="text-center">
                                        <span class="badge badge-pages rounded-pill px-3 py-2"><?= formatNumber($row['total_pages']) ?></span>
                                    </td>
                                    <td class="text-center">
                                        <span class="badge badge-images rounded-pill px-3 py-2"><?= formatNumber($row['total_images']) ?></span>
                                    </td>
                                    <td class="text-center">
                                        <span class="badge badge-tables rounded-pill px-3 py-2"><?= formatNumber($row['total_tables']) ?></span>
                                    </td>
                                    <td>
                                        <span class="badge bg-secondary px-3 py-1"><?= number_format($avg_pages, 1) ?></span>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                                
                                <!-- Grand Totals Row -->
                                <tr style="background-color: #f8f9fa; font-weight: bold;">
                                    <td colspan="2" class="text-end">GRAND TOTALS:</td>
                                    <td class="text-center" style="color: #2ecc71;"><?= formatNumber($grand_totals['total_articles']) ?></td>
                                    <td class="text-center" style="color: #e74c3c;"><?= formatNumber($grand_totals['total_pages']) ?></td>
                                    <td class="text-center"><?= formatNumber($grand_totals['total_images']) ?></td>
                                    <td class="text-center"><?= formatNumber($grand_totals['total_tables']) ?></td>
                                    <td class="text-center">
                                        <?php 
                                        $grand_avg = $grand_totals['total_articles'] > 0 ? 
                                            $grand_totals['total_pages'] / $grand_totals['total_articles'] : 0;
                                        echo number_format($grand_avg, 1);
                                        ?>
                                    </td>
                                </tr>
                            <?php else: ?>
                                <tr>
                                    <td colspan="7" class="text-center py-5">
                                        <div class="text-muted">
                                            <i class="fas fa-chart-line fa-3x mb-3"></i>
                                            <h5>No data found</h5>
                                            <p class="mb-0">
                                                <?php if($client_id > 0): ?>
                                                No articles or pages found for selected client
                                                <?php else: ?>
                                                No articles or pages found for any client
                                                <?php endif; ?>
                                            </p>
                                        </div>
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- Period Information -->
        <?php if(!empty($export_data)): ?>
        <div class="mt-3 text-center text-muted">
            <small>
                <i class="fas fa-info-circle me-1"></i>
                Showing data from <?= formatDate($from_date) ?> to <?= formatDate($to_date) ?>
                <?php if($client_id > 0): ?>
                for selected client only
                <?php endif; ?>
            </small>
        </div>
        <?php endif; ?>
    </div>

    <script src="https://code.jquery.com/jquery-3.7.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        $(document).ready(function() {
            // Auto-submit form on client change
            $('select[name="client_id"]').change(function() {
                $(this).closest('form').submit();
            });
        });
    </script>
</body>
</html>