<br>
<br>
<?php
// dashboard.php - Compact Client Dashboard
require 'db.php';

// Start output buffering to prevent header errors
ob_start();

if (session_status() === PHP_SESSION_NONE) { 
    session_start(); 
}

$pageTitle = "Sales Dashboard";
$currentPage = "dashboard";

// Get current user
$current_user = $_SESSION['username'] ?? '';

// Count total clients
$client_count = 0;
$client_stmt = $mysqli->prepare("SELECT COUNT(*) as total FROM client_master");
if ($client_stmt) {
    $client_stmt->execute();
    $client_result = $client_stmt->get_result();
    $client_data = $client_result->fetch_assoc();
    $client_count = $client_data['total'] ?? 0;
    $client_stmt->close();
}

// Count active clients (clients with jobs)
$active_clients_count = 0;
$active_clients_stmt = $mysqli->prepare("SELECT COUNT(DISTINCT `client_id`) as total FROM `jobs`");
if ($active_clients_stmt) {
    $active_clients_stmt->execute();
    $active_clients_result = $active_clients_stmt->get_result();
    $active_clients_data = $active_clients_result->fetch_assoc();
    $active_clients_count = $active_clients_data['total'] ?? 0;
    $active_clients_stmt->close();
}

// Count left clients (inactive clients from client_master)
$left_clients_count = 0;
$left_clients_stmt = $mysqli->prepare("SELECT COUNT(*) as total FROM client_master WHERE status = 'Inactive'");
if ($left_clients_stmt) {
    $left_clients_stmt->execute();
    $left_clients_result = $left_clients_stmt->get_result();
    $left_clients_data = $left_clients_result->fetch_assoc();
    $left_clients_count = $left_clients_data['total'] ?? 0;
    $left_clients_stmt->close();
}

// Count inactive clients (clients without jobs but might still be active in client_master)
$inactive_clients_count = $client_count - $active_clients_count;

// Calculate percentages
$active_percentage = $client_count > 0 ? round(($active_clients_count / $client_count) * 100, 1) : 0;
$inactive_percentage = $client_count > 0 ? round(($inactive_clients_count / $client_count) * 100, 1) : 0;
$left_percentage = $client_count > 0 ? round(($left_clients_count / $client_count) * 100, 1) : 0;

// Get recent clients (last 5)
$recent_clients = [];
$recent_stmt = $mysqli->prepare("SELECT client_name, contact_person, email, phone, created_date FROM client_master WHERE status = 'Active' ORDER BY created_date DESC LIMIT 5");
if ($recent_stmt) {
    $recent_stmt->execute();
    $recent_result = $recent_stmt->get_result();
    while ($row = $recent_result->fetch_assoc()) {
        $recent_clients[] = $row;
    }
    $recent_stmt->close();
}

// Flush output buffer
ob_end_flush();

// Helper function to get initials from name
function getInitials($name) {
    $name = trim($name);
    if (empty($name)) return '??';
    
    $parts = explode(' ', $name);
    $initials = '';
    
    foreach ($parts as $part) {
        if (!empty($part)) {
            $initials .= strtoupper($part[0]);
            if (strlen($initials) >= 2) break;
        }
    }
    
    return $initials ?: strtoupper(substr($name, 0, 2));
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($pageTitle) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --primary-color: #4361ee;
            --secondary-color: #3f37c9;
            --success-color: #4cc9f0;
            --warning-color: #f8961e;
            --danger-color: #f94144;
            --border-radius: 8px;
            --box-shadow: 0 2px 10px rgba(0, 0, 0, 0.08);
        }

        body {
            background-color: #f8f9fa;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            font-size: 14px;
            padding: 10px;
        }

        /* Compact Header */
        .dashboard-header {
            background: white;
            border-radius: var(--border-radius);
            padding: 15px 20px;
            margin-bottom: 15px;
            box-shadow: var(--box-shadow);
            border-left: 4px solid var(--primary-color);
        }

        .dashboard-title {
            font-size: 1.5rem;
            font-weight: 600;
            margin: 0;
            color: var(--primary-color);
        }

        .dashboard-subtitle {
            color: #6c757d;
            font-size: 0.85rem;
            margin: 5px 0 0 0;
        }

        .user-badge {
            background: #e9ecef;
            padding: 5px 12px;
            border-radius: 20px;
            font-size: 0.85rem;
            color: #495057;
        }

        /* Ultra Compact Stats */
        .compact-stat-card {
            background: white;
            border-radius: var(--border-radius);
            padding: 15px;
            margin-bottom: 15px;
            box-shadow: var(--box-shadow);
            transition: all 0.2s;
            height: 100%;
            border-left: 4px solid;
        }

        .compact-stat-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.1);
        }

        .compact-stat-card.total { border-left-color: var(--primary-color); }
        .compact-stat-card.active { border-left-color: var(--success-color); }
        .compact-stat-card.inactive { border-left-color: var(--warning-color); }
        .compact-stat-card.left { border-left-color: var(--danger-color); }

        .compact-stat-icon {
            font-size: 1.5rem;
            margin-bottom: 10px;
            opacity: 0.8;
        }

        .compact-stat-number {
            font-size: 1.8rem;
            font-weight: 700;
            line-height: 1;
            margin-bottom: 5px;
        }

        .compact-stat-label {
            font-size: 0.75rem;
            color: #6c757d;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 5px;
        }

        .compact-stat-percentage {
            font-size: 0.75rem;
            font-weight: 600;
        }

        /* Progress Bars */
        .compact-progress {
            height: 6px;
            border-radius: 3px;
            background-color: #e9ecef;
        }

        .compact-progress-bar {
            border-radius: 3px;
        }

        /* Recent Clients */
        .compact-table-container {
            background: white;
            border-radius: var(--border-radius);
            padding: 15px;
            box-shadow: var(--box-shadow);
            margin-bottom: 15px;
        }

        .compact-table-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 15px;
            padding-bottom: 10px;
            border-bottom: 1px solid #eef2f7;
        }

        .compact-table-header h6 {
            margin: 0;
            font-weight: 600;
            color: #495057;
            font-size: 0.9rem;
        }

        .compact-client-row {
            display: flex;
            align-items: center;
            padding: 10px 0;
            border-bottom: 1px solid #f8f9fa;
        }

        .compact-client-row:last-child {
            border-bottom: none;
        }

        .compact-client-avatar {
            width: 32px;
            height: 32px;
            background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
            color: white;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 0.75rem;
            font-weight: 600;
            margin-right: 12px;
            flex-shrink: 0;
        }

        .compact-client-info {
            flex: 1;
            min-width: 0; /* Important for text truncation */
        }

        .compact-client-name {
            font-weight: 600;
            font-size: 0.85rem;
            margin-bottom: 2px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .compact-client-details {
            font-size: 0.75rem;
            color: #6c757d;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .compact-client-date {
            font-size: 0.75rem;
            color: #6c757d;
            white-space: nowrap;
            margin-left: 10px;
        }

        /* Distribution Chart */
        .distribution-mini {
            background: white;
            border-radius: var(--border-radius);
            padding: 15px;
            box-shadow: var(--box-shadow);
            margin-bottom: 15px;
        }

        .distribution-item-mini {
            margin-bottom: 12px;
        }

        .distribution-item-mini:last-child {
            margin-bottom: 0;
        }

        .distribution-label-mini {
            display: flex;
            justify-content: space-between;
            font-size: 0.8rem;
            margin-bottom: 4px;
        }

        .distribution-value-mini {
            font-weight: 600;
            font-size: 0.8rem;
        }

        /* Quick Actions */
        .quick-actions {
            background: white;
            border-radius: var(--border-radius);
            padding: 15px;
            box-shadow: var(--box-shadow);
        }

        .quick-action-btn {
            display: flex;
            align-items: center;
            justify-content: center;
            flex-direction: column;
            padding: 10px;
            border-radius: 6px;
            background: #f8f9fa;
            border: 1px solid #e9ecef;
            transition: all 0.2s;
            text-decoration: none;
            color: #495057;
            height: 100%;
        }

        .quick-action-btn:hover {
            background: var(--primary-color);
            color: white;
            transform: translateY(-2px);
            border-color: var(--primary-color);
        }

        .quick-action-icon {
            font-size: 1.2rem;
            margin-bottom: 8px;
        }

        .quick-action-label {
            font-size: 0.75rem;
            font-weight: 500;
            text-align: center;
        }

        /* Responsive */
        @media (max-width: 768px) {
            body {
                padding: 8px;
            }
            
            .dashboard-title {
                font-size: 1.3rem;
            }
            
            .compact-stat-number {
                font-size: 1.5rem;
            }
            
            .compact-stat-icon {
                font-size: 1.3rem;
            }
        }

        @media (max-width: 576px) {
            .compact-client-row {
                flex-wrap: wrap;
            }
            
            .compact-client-date {
                margin-left: 44px; /* avatar width + margin */
                margin-top: 5px;
                width: 100%;
            }
        }

        /* No data state */
        .no-data {
            text-align: center;
            padding: 20px 0;
            color: #6c757d;
        }

        .no-data i {
            font-size: 2rem;
            margin-bottom: 10px;
            opacity: 0.5;
        }

        .no-data p {
            margin: 0;
            font-size: 0.85rem;
        }
    </style>
</head>
<body>
    <?php include 'header.php'; ?>
    
    <!-- Compact Dashboard -->
    <div class="container-fluid px-0">
        <!-- Header -->
        <div class="dashboard-header">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <h1 class="dashboard-title"><i class="fas fa-users me-2"></i>Sales Dashboard</h1>
                    <p class="dashboard-subtitle">
                        Welcome, <strong><?= htmlspecialchars($current_user) ?></strong> • 
                        <?= date('M j, Y') ?>
                    </p>
                </div>
                <div class="user-badge">
                    <i class="fas fa-user-circle me-1"></i><?= htmlspecialchars($current_user) ?>
                </div>
            </div>
        </div>

        <!-- Stats Row - Ultra Compact -->
        <div class="row g-2 mb-3">
            <div class="col-lg-3 col-sm-6">
                <div class="compact-stat-card total">
                    <div class="compact-stat-icon text-primary">
                        <i class="fas fa-users"></i>
                    </div>
                    <div class="compact-stat-number"><?= number_format($client_count) ?></div>
                    <div class="compact-stat-label">Total Clients</div>
                    <div class="compact-progress mt-2">
                        <div class="compact-progress-bar bg-primary" style="width: 100%"></div>
                    </div>
                </div>
            </div>
            
            <div class="col-lg-3 col-sm-6">
                <div class="compact-stat-card active">
                    <div class="compact-stat-icon text-success">
                        <i class="fas fa-user-check"></i>
                    </div>
                    <div class="compact-stat-number"><?= number_format($active_clients_count) ?></div>
                    <div class="compact-stat-label">Active Clients</div>
                    <div class="compact-stat-percentage text-success">
                        <?= $active_percentage ?>% of total
                    </div>
                    <div class="compact-progress mt-1">
                        <div class="compact-progress-bar bg-success" style="width: <?= $active_percentage ?>%"></div>
                    </div>
                </div>
            </div>
            
            <div class="col-lg-3 col-sm-6">
                <div class="compact-stat-card inactive">
                    <div class="compact-stat-icon text-warning">
                        <i class="fas fa-user-clock"></i>
                    </div>
                    <div class="compact-stat-number"><?= number_format($inactive_clients_count) ?></div>
                    <div class="compact-stat-label">Passive Clients</div>
                    <div class="compact-stat-percentage text-warning">
                        <?= $inactive_percentage ?>% of total
                    </div>
                    <div class="compact-progress mt-1">
                        <div class="compact-progress-bar bg-warning" style="width: <?= $inactive_percentage ?>%"></div>
                    </div>
                </div>
            </div>
            
            <div class="col-lg-3 col-sm-6">
                <div class="compact-stat-card left">
                    <div class="compact-stat-icon text-danger">
                        <i class="fas fa-user-times"></i>
                    </div>
                    <div class="compact-stat-number"><?= number_format($left_clients_count) ?></div>
                    <div class="compact-stat-label">Inactive Clients</div>
                    <div class="compact-stat-percentage text-danger">
                        <?= $left_percentage ?>% of total
                    </div>
                    <div class="compact-progress mt-1">
                        <div class="compact-progress-bar bg-danger" style="width: <?= $left_percentage ?>%"></div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Main Content - All in One Row -->
        <div class="row g-2">
            <!-- Left Column: Distribution & Quick Actions -->
            <div class="col-lg-4">
                <!-- Distribution Chart -->
                <div class="distribution-mini">
                    <div class="compact-table-header">
                        <h6><i class="fas fa-chart-pie me-2"></i>Client Distribution</h6>
                        <span class="badge bg-primary" style="font-size: 0.7rem; padding: 3px 8px;">
                            <?= $client_count ?> Total
                        </span>
                    </div>
                    
                    <div class="distribution-item-mini">
                        <div class="distribution-label-mini">
                            <span>Active Clients</span>
                            <span class="distribution-value-mini"><?= $active_percentage ?>%</span>
                        </div>
                        <div class="compact-progress">
                            <div class="compact-progress-bar bg-success" style="width: <?= $active_percentage ?>%"></div>
                        </div>
                        <div class="mt-1" style="font-size: 0.7rem; color: #6c757d;">
                            <?= number_format($active_clients_count) ?> clients
                        </div>
                    </div>
                    
                    <div class="distribution-item-mini">
                        <div class="distribution-label-mini">
                            <span>Passive Clients</span>
                            <span class="distribution-value-mini"><?= $inactive_percentage ?>%</span>
                        </div>
                        <div class="compact-progress">
                            <div class="compact-progress-bar bg-warning" style="width: <?= $inactive_percentage ?>%"></div>
                        </div>
                        <div class="mt-1" style="font-size: 0.7rem; color: #6c757d;">
                            <?= number_format($inactive_clients_count) ?> clients
                        </div>
                    </div>
                    
                    <div class="distribution-item-mini">
                        <div class="distribution-label-mini">
                            <span>Inactive Clients</span>
                            <span class="distribution-value-mini"><?= $left_percentage ?>%</span>
                        </div>
                        <div class="compact-progress">
                            <div class="compact-progress-bar bg-danger" style="width: <?= $left_percentage ?>%"></div>
                        </div>
                        <div class="mt-1" style="font-size: 0.7rem; color: #6c757d;">
                            <?= number_format($left_clients_count) ?> clients
                        </div>
                    </div>
                </div>

                <!-- Quick Actions -->
                <div class="quick-actions">
                    <div class="compact-table-header">
                        <h6><i class="fas fa-bolt me-2"></i>Quick Actions</h6>
                    </div>
                    <div class="row g-2">
                        <div class="col-6">
                            <a href="client_master.php?mode=add" class="quick-action-btn">
                                <div class="quick-action-icon text-primary">
                                    <i class="fas fa-plus"></i>
                                </div>
                                <div class="quick-action-label">Add Client</div>
                            </a>
                        </div>
                        <div class="col-6">
                            <a href="client_master.php" class="quick-action-btn">
                                <div class="quick-action-icon text-success">
                                    <i class="fas fa-list"></i>
                                </div>
                                <div class="quick-action-label">View All</div>
                            </a>
                        </div>
                        <div class="col-6">
                            <a href="client_master.php?q=&status=Active" class="quick-action-btn">
                                <div class="quick-action-icon text-info">
                                    <i class="fas fa-user-check"></i>
                                </div>
                                <div class="quick-action-label">Active</div>
                            </a>
                        </div>
                        <div class="col-6">
                            <a href="client_master.php?q=&status=Inactive" class="quick-action-btn">
                                <div class="quick-action-icon text-danger">
                                    <i class="fas fa-user-times"></i>
                                </div>
                                <div class="quick-action-label">Inactive</div>
                            </a>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Right Column: Recent Clients -->
            <div class="col-lg-8">
                <div class="compact-table-container" style="height: 100%;">
                    <div class="compact-table-header">
                        <h6><i class="fas fa-user-plus me-2"></i>Recent Clients</h6>
                        <a href="client_master.php" class="btn btn-sm btn-primary" style="font-size: 0.75rem; padding: 3px 10px;">
                            View All <i class="fas fa-arrow-right ms-1"></i>
                        </a>
                    </div>
                    
                    <?php if (!empty($recent_clients)): ?>
                        <?php foreach ($recent_clients as $client): 
                            $initials = getInitials($client['client_name']);
                            $added_date = date('M d', strtotime($client['created_date']));
                        ?>
                        <div class="compact-client-row">
                            <div class="compact-client-avatar">
                                <?= $initials ?>
                            </div>
                            <div class="compact-client-info">
                                <div class="compact-client-name" title="<?= htmlspecialchars($client['client_name']) ?>">
                                    <?= htmlspecialchars($client['client_name']) ?>
                                </div>
                                <div class="compact-client-details">
                                    <?php if (!empty($client['contact_person'])): ?>
                                        <span title="Contact: <?= htmlspecialchars($client['contact_person']) ?>">
                                            <i class="fas fa-user me-1"></i><?= htmlspecialchars($client['contact_person']) ?>
                                        </span>
                                        •
                                    <?php endif; ?>
                                    <span title="Email: <?= htmlspecialchars($client['email']) ?>">
                                        <i class="fas fa-envelope me-1"></i><?= htmlspecialchars($client['email']) ?>
                                    </span>
                                    <?php if (!empty($client['phone'])): ?>
                                        •
                                        <span title="Phone: <?= htmlspecialchars($client['phone']) ?>">
                                            <i class="fas fa-phone me-1"></i><?= htmlspecialchars($client['phone']) ?>
                                        </span>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <div class="compact-client-date" title="Added on <?= date('M d, Y', strtotime($client['created_date'])) ?>">
                                <i class="far fa-calendar me-1"></i><?= $added_date ?>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div class="no-data">
                            <i class="fas fa-users"></i>
                            <p>No clients found</p>
                            <a href="client_master.php?mode=add" class="btn btn-sm btn-primary mt-2" style="font-size: 0.75rem;">
                                <i class="fas fa-plus me-1"></i> Add First Client
                            </a>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Summary Footer -->
        <div class="row mt-2">
            <div class="col-12">
                <div class="dashboard-header" style="padding: 10px 15px;">
                    <div class="row text-center">
                        <div class="col-md-4 border-end">
                            <small class="text-muted d-block" style="font-size: 0.75rem;">Active Client Rate</small>
                            <strong class="text-success" style="font-size: 1.1rem;"><?= $active_percentage ?>%</strong>
                        </div>
                        <div class="col-md-4 border-end">
                            <small class="text-muted d-block" style="font-size: 0.75rem;">Passive Client Rate</small>
                            <strong class="text-warning" style="font-size: 1.1rem;"><?= $inactive_percentage ?>%</strong>
                        </div>
                        <div class="col-md-4">
                            <small class="text-muted d-block" style="font-size: 0.75rem;">Inactive Client Rate</small>
                            <strong class="text-danger" style="font-size: 1.1rem;"><?= $left_percentage ?>%</strong>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // Update date every minute
            setInterval(updateDate, 60000);
            
            // Add hover effects
            document.querySelectorAll('.compact-stat-card').forEach(card => {
                card.addEventListener('mouseenter', function() {
                    this.style.transform = 'translateY(-3px)';
                });
                
                card.addEventListener('mouseleave', function() {
                    this.style.transform = 'translateY(0)';
                });
            });
        });

        function updateDate() {
            const now = new Date();
            const options = { month: 'short', day: 'numeric', year: 'numeric' };
            const formattedDate = now.toLocaleDateString('en-US', options);
            
            const dateElement = document.querySelector('.dashboard-subtitle');
            if (dateElement) {
                const welcomeText = dateElement.innerHTML.split('•')[0];
                dateElement.innerHTML = welcomeText + ' • ' + formattedDate;
            }
        }

        // Tooltip initialization for truncated text
        document.querySelectorAll('[title]').forEach(element => {
            element.addEventListener('mouseenter', function() {
                if (this.offsetWidth < this.scrollWidth) {
                    this.setAttribute('data-bs-toggle', 'tooltip');
                    this.setAttribute('data-bs-placement', 'top');
                }
            });
        });
    </script>
</body>
</html>