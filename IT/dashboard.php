<?php
// IT/dashboard.php - Client Dashboard
require 'db.php';

if (session_status() === PHP_SESSION_NONE) { 
    session_start(); 
}

// Session guard for IT
if (!isset($_SESSION['itname']) && !isset($_SESSION['infoname']) && !isset($_SESSION['uid'])) { 
    header("Location: ../index.php"); 
    exit; 
}

$pageTitle = "Client Dashboard";
$currentPage = "dashboard.php";

// Current user
$current_user = $_SESSION['username'] ?? 'User';

// TOTAL CLIENTS
$total_clients = 0;
$total_stmt = $mysqli->prepare("SELECT COUNT(*) as total FROM client_master");
if ($total_stmt) {
    $total_stmt->execute();
    $total_result = $total_stmt->get_result();
    $total_data = $total_result->fetch_assoc();
    $total_clients = (int)($total_data['total'] ?? 0);
    $total_stmt->close();
}

// ACTIVE CLIENTS (clients with jobs)
$active_clients = 0;
$active_stmt = $mysqli->prepare("SELECT COUNT(DISTINCT `client_id`) as total FROM `jobs`");
if ($active_stmt) {
    $active_stmt->execute();
    $active_result = $active_stmt->get_result();
    $active_data = $active_result->fetch_assoc();
    $active_clients = (int)($active_data['total'] ?? 0);
    $active_stmt->close();
}

// PASSIVE CLIENTS (active in client_master but no jobs)
$passive_clients = 0;
$passive_stmt = $mysqli->prepare("SELECT COUNT(*) as total FROM client_master WHERE status = 'Active'");
if ($passive_stmt) {
    $passive_stmt->execute();
    $passive_result = $passive_stmt->get_result();
    $passive_data = $passive_result->fetch_assoc();
    $passive_clients = (int)($passive_data['total'] ?? 0);
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
    $inactive_clients = (int)($inactive_data['total'] ?? 0);
    $inactive_stmt->close();
}

// Calculate percentages
$active_percentage = $total_clients > 0 ? round(($active_clients / $total_clients) * 100, 1) : 0;
$passive_percentage = $total_clients > 0 ? round(($passive_clients / $total_clients) * 100, 1) : 0;
$inactive_percentage = $total_clients > 0 ? round(($inactive_clients / $total_clients) * 100, 1) : 0;

include 'header.php';
?>

<style>
    :root {
        --primary-color: #4361ee;
        --secondary-color: #3f37c9;
        --success-color: #198754;
        --warning-color: #f8961e;
        --danger-color: #f94144;
        --border-radius: 8px;
        --box-shadow: 0 2px 10px rgba(0, 0, 0, 0.08);
    }

    body {
        background-color: #f8f9fa;
        font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        font-size: 14px;
    }

    .dashboard-container {
        padding: 90px 20px 30px 20px;
        width: 100%;
    }

    /* Compact Header */
    .dashboard-header {
        background: white;
        border-radius: var(--border-radius);
        padding: 15px 20px;
        margin-bottom: 20px;
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
        color: #212529;
    }

    .stat-label {
        font-size: 0.85rem;
        color: #6c757d;
        text-transform: uppercase;
        letter-spacing: 0.5px;
        margin-bottom: 10px;
        font-weight: 600;
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

    @media (max-width: 768px) {
        .dashboard-container {
            padding: 80px 10px 20px 10px;
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

<div class="dashboard-container">
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

        <!-- Stats Cards -->
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
                        <div class="progress-bar bg-success" style="width: <?= max(0, min(100, $active_percentage)) ?>%"></div>
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
                        <div class="progress-bar bg-warning" style="width: <?= max(0, min(100, $passive_percentage)) ?>%"></div>
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
                        <div class="progress-bar bg-danger" style="width: <?= max(0, min(100, $inactive_percentage)) ?>%"></div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
    document.addEventListener('DOMContentLoaded', function() {
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
