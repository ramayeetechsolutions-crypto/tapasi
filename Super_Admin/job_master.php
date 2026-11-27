<?php
require 'db.php';

if (session_status() === PHP_SESSION_NONE) { session_start(); }

// ---------- Helpers ----------
function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function formatDate($d) {
    return (!empty($d) && $d != '0000-00-00') ? date('d M, Y', strtotime($d)) : '—';
}
function redirect_with($params =[]) {
    $base = 'job_master.php';
    if (!empty($params)) $base .= '?' . http_build_query($params);
    header("Location: $base");
    exit;
}

// ---------- Handle POST actions (Logic remains same) ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $job_name = trim($_POST['job_name'] ?? '');
    $rate     = trim($_POST['rate'] ?? '');
    $desc     = trim($_POST['job_description'] ?? '');
    $uid      = 'Super Admin';

    if ($action === 'add') {
        $stmt = $mysqli->prepare("INSERT INTO job_master (job_name, rate, job_description, status, created_by, created_date) VALUES (?, ?, ?, 'Active', ?, NOW())");
        $stmt->bind_param("sdss", $job_name, $rate, $desc, $uid);
        $stmt->execute();
        redirect_with(['msg'=>"Job created successfully",'type'=>'success']);
    }
    if ($action === 'edit') {
        $id = (int)$_POST['job_id'];
        $stmt = $mysqli->prepare("UPDATE job_master SET job_name=?, rate=?, job_description=? WHERE job_id=?");
        $stmt->bind_param("sdsi", $job_name, $rate, $desc, $id);
        $stmt->execute();
        redirect_with(['msg'=>"Job updated successfully",'type'=>'success']);
    }
    if ($action === 'delete') {
        $id = (int)$_POST['job_id'];
        $mysqli->query("DELETE FROM job_master WHERE job_id=$id");
        redirect_with(['msg'=>"Job deleted",'type'=>'warning']);
    }
    if ($action === 'toggle') {
        $id = (int)$_POST['job_id'];
        $mysqli->query("UPDATE job_master SET status = IF(status='Active', 'Inactive', 'Active') WHERE job_id=$id");
        redirect_with(['msg'=>"Status updated",'type'=>'info']);
    }
}

// ---------- Data Fetching ----------
$mode = $_GET['mode'] ?? 'view';
$editJob = null;
if ($mode === 'edit' && isset($_GET['id'])) {
    $stmt = $mysqli->prepare("SELECT * FROM job_master WHERE job_id=?");
    $stmt->bind_param("i", $_GET['id']);
    $stmt->execute();
    $editJob = $stmt->get_result()->fetch_assoc();
}

$q = trim($_GET['q'] ?? '');
$fstatus = $_GET['status'] ?? '';
$sql = "SELECT * FROM job_master WHERE 1=1";
if($q) $sql .= " AND (job_name LIKE '%$q%' OR job_description LIKE '%$q%')";
if($fstatus) $sql .= " AND status='$fstatus'";
$sql .= " ORDER BY job_id DESC";
$jobs = $mysqli->query($sql);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Job Master Management</title>
    <!-- Google Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <!-- Bootstrap 5 & FontAwesome -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <style>
        /* Ash Page Header Banner (Matching Navbar Color) */
        .page-title-container {
            background: var(--ash, #6c757d);
            color: white;
            padding: 12px 20px;
            border-radius: 12px;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
            flex-wrap: wrap;
            gap: 15px;
        }

        .page-title-container h1,
        .page-title-container h2,
        .page-title-container h3,
        .page-title-container h4,
        .page-title-container h5,
        .page-title-container h6 {
            margin: 0;
            font-size: 1.25rem;
            font-weight: 600;
            color: white;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .page-title-container p {
            color: rgba(255, 255, 255, 0.85);
            margin: 0;
        }

        .page-title-container i {
            color: white;
        }

        :root {
            --primary-color: #4361ee;
            --secondary-color: #e2e8f0;
            --bg-body: #f8fafc;
            --card-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.07), 0 4px 6px -2px rgba(0, 0, 0, 0.05);
        }

        body {
            font-family: 'Inter', sans-serif;
            background-color: var(--bg-body);
            color: #1e293b;
        }

        .main-container { padding-top: 10px !important; padding-bottom: 30px; }

        /* Card Styling */
        .card {
            border: none;
            border-radius: 12px;
            box-shadow: var(--card-shadow);
            background: #ffffff;
        }

        .card-header {
            background: transparent;
            border-bottom: 1px solid #f1f5f9;
            padding: 1.25rem;
            font-weight: 700;
            color: #334155;
        }

        /* Table Styling */
        .table { margin-bottom: 0; }
        .table thead th {
            background-color: #f8fafc;
            text-transform: uppercase;
            font-size: 0.75rem;
            letter-spacing: 0.05em;
            font-weight: 600;
            padding: 1rem;
            border-top: none;
            color: #64748b;
        }
        .table tbody td { padding: 1rem; vertical-align: middle; border-color: #f1f5f9; }
        .table-hover tbody tr:hover { background-color: #f1f5f9; transition: 0.2s; }

        /* Status Badges */
        .badge-status {
            padding: 0.5em 1em;
            border-radius: 50px;
            font-weight: 500;
            font-size: 0.8rem;
            cursor: pointer;
            border: none;
            transition: transform 0.2s;
        }
        .badge-status:hover { transform: scale(1.05); }
        .bg-active { background-color: #dcfce7; color: #166534; }
        .bg-inactive { background-color: #fee2e2; color: #991b1b; }

        /* Form Controls */
        .form-control, .form-select {
            border-radius: 8px;
            border: 1px solid #e2e8f0;
            padding: 0.6rem 0.8rem;
            font-size: 0.9rem;
        }
        .form-control:focus {
            box-shadow: 0 0 0 3px rgba(67, 97, 238, 0.15);
            border-color: var(--primary-color);
        }

        /* Buttons */
        .btn { border-radius: 8px; font-weight: 500; transition: all 0.3s; }
        .btn-primary { background-color: var(--primary-color); border: none; }
        .btn-primary:hover { background-color: #3730a3; }
        
        .action-btn {
            width: 32px;
            height: 32px;
            padding: 0;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            border-radius: 6px;
        }

        /* Animation */
        .fade-in { animation: fadeIn 0.5s ease-in; }
        @keyframes fadeIn { from { opacity: 0; transform: translateY(10px); } to { opacity: 1; transform: translateY(0); } }
    </style>
</head>
<body>

<?php include 'header.php'; // Ensure this doesn't have broken HTML ?>

<div class="container main-container">
    
    <!-- Toast Notifications -->
    <?php if (!empty($_GET['msg'])): ?>
    <div class="alert alert-<?= h($_GET['type'] ?? 'info') ?> alert-dismissible fade show border-0 shadow-sm mb-4" role="alert">
        <i class="fa-solid fa-circle-check me-2"></i> <?= h($_GET['msg']) ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    <?php endif; ?>

    <?php if($mode === 'add' || $mode === 'edit'): ?>
    <!-- Add/Edit Form Section -->
    <div class="row justify-content-center fade-in">
        <div class="col-md-6">
            <div class="page-title-container" style="background-color: #6c757d !important; background: #6c757d !important; color: #ffffff !important; padding: 12px 20px !important; border-radius: 12px !important; margin-bottom: 20px !important; display: flex !important; justify-content: space-between !important; align-items: center !important;">
                <h4 class="mb-0 text-white" style="color: #ffffff !important; font-size: 1.25rem !important;"><i class="fa-solid <?= $mode==='edit'?'fa-pen-to-square':'fa-plus-circle' ?> me-2"></i><?= $mode==='edit'?'Modify Job Detail':'Register New Job' ?></h4>
                <a href="job_master.php" class="btn btn-secondary btn-sm" style="margin-left: auto !important;"><i class="fas fa-arrow-left me-1"></i> Back to List</a>
            </div>
            <div class="card">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <span><i class="fa-solid <?= $mode==='edit'?'fa-pen-to-square':'fa-plus-circle' ?> me-2"></i><?= $mode==='edit'?'Modify Job Detail':'Register New Job' ?></span>
                    <a href="job_master.php" class="btn-close" aria-label="Close"></a>
                </div>
                <div class="card-body p-4">
                    <form method="post" autocomplete="off">
                        <input type="hidden" name="action" value="<?= $mode==='edit'?'edit':'add' ?>">
                        <?php if($mode==='edit'): ?><input type="hidden" name="job_id" value="<?= (int)$editJob['job_id'] ?>"><?php endif; ?>
                        
                        <div class="mb-4">
                            <label class="form-label fw-semibold">Job Title</label>
                            <input type="text" name="job_name" class="form-control" placeholder="e.g. Graphic Designing" required value="<?= h($editJob['job_name'] ?? '') ?>">
                        </div>

                        <div class="mb-4">
                            <label class="form-label fw-semibold">Description</label>
                            <textarea name="job_description" class="form-control" rows="4" placeholder="Briefly describe the job tasks..."><?= h($editJob['job_description'] ?? '') ?></textarea>
                        </div>

                        <div class="d-grid gap-2">
                            <button type="submit" class="btn btn-primary btn-lg"><?= $mode==='edit'?'Update Job':'Create Job' ?></button>
                            <a href="job_master.php" class="btn btn-light">Cancel</a>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <?php else: ?>

    <!-- Header Actions -->
    <div class="page-title-container" style="background-color: #6c757d !important; background: #6c757d !important; color: #ffffff !important; padding: 12px 20px !important; border-radius: 12px !important; margin-bottom: 20px !important; display: flex !important; justify-content: space-between !important; align-items: center !important;">
        <div>
            <h3 class="fw-bold mb-0 text-white" style="color: #ffffff !important; font-size: 1.25rem !important;"><i class="fas fa-briefcase me-2"></i>Job Master</h3>
            <p class="small mb-0 text-white-50" style="color: rgba(255, 255, 255, 0.85) !important;">Manage your company's service catalog and roles.</p>
        </div>
        <a href="job_master.php?mode=add" class="btn btn-success shadow-sm" style="margin-left: auto !important;">
            <i class="fa fa-plus me-2"></i>Add New Job
        </a>
    </div>

    <!-- Filter Card -->
    <div class="card mb-4 fade-in">
        <div class="card-body p-3">
            <form method="get" class="row g-2 align-items-center">
                <div class="col-md-5">
                    <div class="input-group">
                        <span class="input-group-text bg-transparent border-end-0 text-muted"><i class="fa fa-search"></i></span>
                        <input type="text" name="q" value="<?= h($q) ?>" class="form-control border-start-0" placeholder="Search by name or description...">
                    </div>
                </div>
                <div class="col-md-3">
                    <select name="status" class="form-select">
                        <option value="">All Status</option>
                        <option value="Active" <?= $fstatus==='Active'?'selected':'' ?>>Active</option>
                        <option value="Inactive" <?= $fstatus==='Inactive'?'selected':'' ?>>Inactive</option>
                    </select>
                </div>
                <div class="col-md-4 d-flex gap-2">
                    <button type="submit" class="btn btn-dark px-4"><i class="fas fa-filter me-1"></i> Apply Filters</button>
                    <a href="job_master.php" class="btn btn-outline-secondary px-4"><i class="fas fa-undo me-1"></i> Reset</a>
                </div>
            </form>
        </div>
    </div>

    <!-- Table Card -->
    <div class="card fade-in">
        <div class="table-responsive">
            <table class="table table-hover">
                <thead>
                    <tr>
                        <th class="ps-4">S.No</th>
                        <th>Job Information</th>
                        <th>Created By</th>
                        <th>Created Date</th>
                        <th class="text-center">Status</th>
                        <th class="text-end pe-4 text-nowrap" style="white-space: nowrap !important; min-width: 90px; width: 100px;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if($jobs && $jobs->num_rows): $i=0; while($row=$jobs->fetch_assoc()): $i++; ?>
                    <tr>
                        <td class="ps-4 text-muted small"><?= $i ?></td>
                        <td>
                            <div class="fw-bold text-dark"><?= h($row['job_name']) ?></div>
                            <div class="text-muted small text-truncate" style="max-width: 250px;"><?= h($row['job_description'] ?: 'No description provided') ?></div>
                        </td>
                        <td>
                            <div class="d-flex align-items-center">
                                <div class="bg-light rounded-circle d-flex align-items-center justify-content-center me-2" style="width: 30px; height: 30px;">
                                    <i class="fa fa-user text-muted" style="font-size: 0.8rem;"></i>
                                </div>
                                <span class="small fw-medium"><?= h($row['created_by']) ?></span>
                            </div>
                        </td>
                        <td class="small text-muted"><?= formatDate($row['created_date']) ?></td>
                        <td class="text-center">
                            <form method="post" class="d-inline">
                                <input type="hidden" name="action" value="toggle">
                                <input type="hidden" name="job_id" value="<?= (int)$row['job_id'] ?>">
                                <button type="submit" class="badge-status <?= $row['status']==='Active'?'bg-active':'bg-inactive' ?>" onclick="return confirm('Change status?')">
                                    <i class="fa-solid fa-circle me-1" style="font-size: 0.5rem;"></i> <?= h($row['status']) ?>
                                </button>
                            </form>
                        </td>
                        <td class="text-end pe-4 text-nowrap" style="white-space: nowrap !important;">
                            <div class="d-inline-flex align-items-center justify-content-end gap-1 text-nowrap" style="white-space: nowrap !important;">
                                <a href="job_master.php?mode=edit&id=<?= (int)$row['job_id'] ?>" class="btn btn-light action-btn text-warning me-1" title="Edit">
                                    <i class="fa fa-edit"></i>
                                </a>
                                <form method="post" class="d-inline m-0 p-0" onsubmit="return confirm('Are you sure you want to delete this job?')">
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="job_id" value="<?= (int)$row['job_id'] ?>">
                                    <button class="btn btn-light action-btn text-danger" title="Delete">
                                        <i class="fa fa-trash"></i>
                                    </button>
                                </form>
                            </div>
                        </td>
                    </tr>
                    <?php endwhile; else: ?>
                    <tr>
                        <td colspan="6" class="text-center py-5">
                            <img src="https://cdn-icons-png.flaticon.com/512/7486/7486744.png" width="80" class="opacity-25 mb-3" alt="Empty">
                            <p class="text-muted">No jobs found matching your criteria.</p>
                        </td>
                    </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endif; ?>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>