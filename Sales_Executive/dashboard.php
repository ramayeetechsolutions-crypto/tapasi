<br><br><br>
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

// TOTAL CLIENTS
$total_clients = 0;
$total_stmt = $mysqli->prepare("SELECT COUNT(*) as total FROM client_master");
if ($total_stmt) {
    $total_stmt->execute();
    $total_result = $total_stmt->get_result();
    $total_data = $total_result->fetch_assoc();
    $total_clients = $total_data['total'] ?? 0;
    $total_stmt->close();
}

// ACTIVE CLIENTS (clients with jobs)
$active_clients = 0;
$active_stmt = $mysqli->prepare("SELECT COUNT(DISTINCT `client_id`) as total FROM `jobs`");
if ($active_stmt) {
    $active_stmt->execute();
    $active_result = $active_stmt->get_result();
    $active_data = $active_result->fetch_assoc();
    $active_clients = $active_data['total'] ?? 0;
    $active_stmt->close();
}

// PASSIVE CLIENTS (active in client_master but no jobs)
$passive_clients = 0;
$passive_stmt = $mysqli->prepare("SELECT COUNT(*) as total FROM client_master WHERE status = 'Active'");
if ($passive_stmt) {
    $passive_stmt->execute();
    $passive_result = $passive_stmt->get_result();
    $passive_data = $passive_result->fetch_assoc();
    $passive_clients = $passive_data['total'] ?? 0;
    $passive_stmt->close();
}
$passive_clients = $passive_clients - $active_clients; // Active in master but no jobs

// INACTIVE CLIENTS (status = 'Inactive' in client_master)
$inactive_clients = 0;
$inactive_stmt = $mysqli->prepare("SELECT COUNT(*) as total FROM client_master WHERE status = 'Inactive'");
if ($inactive_stmt) {
    $inactive_stmt->execute();
    $inactive_result = $inactive_stmt->get_result();
    $inactive_data = $inactive_result->fetch_assoc();
    $inactive_clients = $inactive_data['total'] ?? 0;
    $inactive_stmt->close();
}

// Calculate percentages
$active_percentage = $total_clients > 0 ? round(($active_clients / $total_clients) * 100, 1) : 0;
$passive_percentage = $total_clients > 0 ? round(($passive_clients / $total_clients) * 100, 1) : 0;
$inactive_percentage = $total_clients > 0 ? round(($inactive_clients / $total_clients) * 100, 1) : 0;

// Flush output buffer
ob_end_flush();
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

        /* Stat Cards */
        .stat-card {
            background: white;
            border-radius: var(--border-radius);
            padding: 20px;
            margin-bottom: 20px;
            box-shadow: var(--box-shadow);
            transition: all 0.2s;
            height: 100%;
            border-left: 4px solid;
            position: relative;
            overflow: hidden;
        }

        .stat-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.1);
        }

        .stat-card.total { border-left-color: var(--primary-color); }
        .stat-card.active { border-left-color: var(--success-color); }
        .stat-card.passive { border-left-color: var(--warning-color); }
        .stat-card.inactive { border-left-color: var(--danger-color); }

        .stat-icon {
            font-size: 2rem;
            margin-bottom: 10px;
            opacity: 0.8;
        }

        .stat-number {
            font-size: 2.5rem;
            font-weight: 700;
            line-height: 1;
            margin-bottom: 5px;
        }

        .stat-label {
            font-size: 0.85rem;
            color: #6c757d;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 10px;
        }

        .stat-percentage {
            font-size: 0.85rem;
            font-weight: 600;
            margin-bottom: 10px;
        }

        .stat-percentage.total { color: var(--primary-color); }
        .stat-percentage.active { color: var(--success-color); }
        .stat-percentage.passive { color: var(--warning-color); }
        .stat-percentage.inactive { color: var(--danger-color); }

        /* Progress Bars */
        .progress {
            height: 6px;
            border-radius: 3px;
            background-color: #e9ecef;
            margin-top: 5px;
        }

        .progress-bar {
            border-radius: 3px;
        }

        /* Quick Actions */
        .quick-actions {
            background: white;
            border-radius: var(--border-radius);
            padding: 15px;
            box-shadow: var(--box-shadow);
            margin-top: 20px;
        }

        .quick-actions h6 {
            margin: 0 0 15px 0;
            font-weight: 600;
            color: #495057;
            font-size: 0.9rem;
            border-bottom: 1px solid #eef2f7;
            padding-bottom: 10px;
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
            
            .stat-number {
                font-size: 2rem;
            }
            
            .stat-icon {
                font-size: 1.8rem;
            }
        }
    </style>
</head>
<body>
    <?php include 'header.php'; ?>
    
    <!-- Dashboard -->
    <div class="container-fluid px-0">
        <!-- Header -->
        <div class="dashboard-header">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <h1 class="dashboard-title"><i class="fas fa-chart-line me-2"></i>Client Dashboard</h1>
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

        <!-- Stats Cards - Main Focus -->
        <div class="row">
            <!-- Total Clients -->
            <div class="col-md-3">
                <div class="stat-card total">
                    <div class="stat-icon text-primary">
                        <i class="fas fa-users"></i>
                    </div>
                    <div class="stat-number"><?= number_format($total_clients) ?></div>
                    <div class="stat-label">Total Clients</div>
                    <div class="stat-percentage total">100% of total</div>
                    <div class="progress">
                        <div class="progress-bar bg-primary" style="width: 100%"></div>
                    </div>
                </div>
            </div>
            
            <!-- Active Clients -->
            <div class="col-md-3">
                <div class="stat-card active">
                    <div class="stat-icon text-success">
                        <i class="fas fa-user-check"></i>
                    </div>
                    <div class="stat-number"><?= number_format($active_clients) ?></div>
                    <div class="stat-label">Active Clients</div>
                    <div class="stat-percentage active"><?= $active_percentage ?>% of total</div>
                    <div class="progress">
                        <div class="progress-bar bg-success" style="width: <?= $active_percentage ?>%"></div>
                    </div>
                </div>
            </div>
            
            <!-- Passive Clients -->
            <div class="col-md-3">
                <div class="stat-card passive">
                    <div class="stat-icon text-warning">
                        <i class="fas fa-user-clock"></i>
                    </div>
                    <div class="stat-number"><?= number_format($passive_clients) ?></div>
                    <div class="stat-label">Passive Clients</div>
                    <div class="stat-percentage passive"><?= $passive_percentage ?>% of total</div>
                    <div class="progress">
                        <div class="progress-bar bg-warning" style="width: <?= $passive_percentage ?>%"></div>
                    </div>
                </div>
            </div>
            
            <!-- Inactive Clients -->
            <div class="col-md-3">
                <div class="stat-card inactive">
                    <div class="stat-icon text-danger">
                        <i class="fas fa-user-times"></i>
                    </div>
                    <div class="stat-number"><?= number_format($inactive_clients) ?></div>
                    <div class="stat-label">Inactive Clients</div>
                    <div class="stat-percentage inactive"><?= $inactive_percentage ?>% of total</div>
                    <div class="progress">
                        <div class="progress-bar bg-danger" style="width: <?= $inactive_percentage ?>%"></div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Summary Row -->
        <!--div class="row mt-2">
            <div class="col-12">
                <div class="quick-actions">
                    <h6><i class="fas fa-chart-pie me-2"></i>Client Distribution Summary</h6>
                    <div class="row text-center">
                        <div class="col-md-3 border-end">
                            <small class="text-muted d-block" style="font-size: 0.75rem;">Total Clients</small>
                            <strong class="text-primary" style="font-size: 1.2rem;"><?= number_format($total_clients) ?></strong>
                        </div>
                        <div class="col-md-3 border-end">
                            <small class="text-muted d-block" style="font-size: 0.75rem;">Active Clients</small>
                            <strong class="text-success" style="font-size: 1.2rem;"><?= number_format($active_clients) ?> (<?= $active_percentage ?>%)</strong>
                        </div>
                        <div class="col-md-3 border-end">
                            <small class="text-muted d-block" style="font-size: 0.75rem;">Passive Clients</small>
                            <strong class="text-warning" style="font-size: 1.2rem;"><?= number_format($passive_clients) ?> (<?= $passive_percentage ?>%)</strong>
                        </div>
                        <div class="col-md-3">
                            <small class="text-muted d-block" style="font-size: 0.75rem;">Inactive Clients</small>
                            <strong class="text-danger" style="font-size: 1.2rem;"><?= number_format($inactive_clients) ?> (<?= $inactive_percentage ?>%)</strong>
                        </div>
                    </div>
                </div>
            </div>
        </div-->

        <!-- Quick Actions Row -->
        <!--div class="row mt-3">
            <div class="col-12">
                <div class="quick-actions">
                    <h6><i class="fas fa-bolt me-2"></i>Quick Actions</h6>
                    <div class="row g-2">
                        <div class="col-md-3 col-6">
                            <a href="client_master.php?mode=add" class="quick-action-btn">
                                <div class="quick-action-icon text-primary">
                                    <i class="fas fa-plus"></i>
                                </div>
                                <div class="quick-action-label">Add Client</div>
                            </a>
                        </div>
                        <div class="col-md-3 col-6">
                            <a href="client_master.php" class="quick-action-btn">
                                <div class="quick-action-icon text-success">
                                    <i class="fas fa-list"></i>
                                </div>
                                <div class="quick-action-label">All Clients</div>
                            </a>
                        </div>
                        <div class="col-md-3 col-6">
                            <a href="client_master.php?status=Active" class="quick-action-btn">
                                <div class="quick-action-icon text-info">
                                    <i class="fas fa-user-check"></i>
                                </div>
                                <div class="quick-action-label">View Active</div>
                            </a>
                        </div>
                        <div class="col-md-3 col-6">
                            <a href="client_master.php?status=Inactive" class="quick-action-btn">
                                <div class="quick-action-icon text-danger">
                                    <i class="fas fa-user-times"></i>
                                </div>
                                <div class="quick-action-label">View Inactive</div>
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        </div-->
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // Add hover effects
            document.querySelectorAll('.stat-card').forEach(card => {
                card.addEventListener('mouseenter', function() {
                    this.style.transform = 'translateY(-3px)';
                });
                
                card.addEventListener('mouseleave', function() {
                    this.style.transform = 'translateY(0)';
                });
            });
        });
    </script>
</body>
</html>