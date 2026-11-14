<?php
// -------------------------------------------------------------
// FILE: employee_official_info.php
// PURPOSE: Displays ONLY Official/Salary Information (UI Enhanced)
// -------------------------------------------------------------

require 'db.php';
ini_set('display_errors', 1);
error_reporting(E_ALL);

if (session_status() === PHP_SESSION_NONE) { 
    session_start(); 
}

// --- EXPORT LOGIC (OFFICIAL INFO ONLY - PRESERVED 100%) ---
if (isset($_GET['export'])) {
    error_reporting(0); 
    ini_set('display_errors', 0);
    if (ob_get_level()) { ob_end_clean(); }

    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="official_info_' . date('Y-m-d') . '.csv"');
    
    $output = fopen('php://output', 'w');
    
    // Header - Official Columns Only
    fputcsv($output, array('Employee ID', 'Name', 'DOJ', 'Designation', 'Shift', 'Salary', 'Bank', 'Account No', 'IFSC', 'PAN', 'UAN', 'Status'), ",", "\"", "\\");
    
    $exp_query = "SELECT * FROM employee_master ORDER BY employee_id DESC";
    $exp_run = $mysqli->query($exp_query);
    
    while ($row = $exp_run->fetch_assoc()) {
        $doj = ($row['joining_date'] == '0000-00-00' || empty($row['joining_date'])) ? '' : date('d-m-Y', strtotime($row['joining_date']));
        $account = "\t" . $row['account_no'];

        fputcsv($output, array(
            $row['employee_username'], 
            $row['employee_name'], 
            $doj,
            $row['designation_id'], 
            $row['shift'],
            $row['salary'],
            $row['bank_name'], 
            $account, 
            $row['ifsc_code'], 
            $row['pan_no'], 
            $row['uan_no'], 
            $row['status']
        ), ",", "\"", "\\");
    }
    fclose($output);
    exit();
}

$pageTitle = "Official Employee Info";
$currentPage = "employee_master.php";
include 'header.php'; 

// --- PAGINATION ---
$results_per_page = 10; 
$page = (isset($_GET['page']) && is_numeric($_GET['page'])) ? (int) $_GET['page'] : 1;
$offset = ($page - 1) * $results_per_page;

$count_query = "SELECT COUNT(*) AS total FROM employee_master";
$count_result = $mysqli->query($count_query);
$count_row = $count_result ? $count_result->fetch_assoc() : ['total' => 0];
$total_matching = (int)($count_row['total'] ?? 0);
$total_pages = ceil($total_matching / $results_per_page);
?>

<style>
    .main-content {
        margin-top: 80px;
        padding-bottom: 50px;
    }
    .header-bar {
        background: transparent;
        padding: 0 0 16px 0;
    }
    .page-main-heading {
        color: #1e293b;
        font-weight: 700;
        font-size: 1.35rem;
        letter-spacing: -0.3px;
        margin: 0;
        display: flex;
        align-items: center;
        gap: 8px;
    }
    .back-btn-pill {
        background: #f1f5f9;
        color: #475569;
        border: 1px solid #cbd5e1;
        border-radius: 8px;
        padding: 7px 16px;
        font-size: 0.84rem;
        font-weight: 600;
        text-decoration: none;
        display: inline-flex;
        align-items: center;
        gap: 6px;
        transition: all 0.2s ease;
    }
    .back-btn-pill:hover {
        background: #e2e8f0;
        color: #1e293b;
        transform: translateX(-2px);
    }
    .export-btn-pill {
        border-radius: 8px;
        padding: 7px 16px;
        font-size: 0.84rem;
        font-weight: 600;
        display: inline-flex;
        align-items: center;
        gap: 6px;
        transition: all 0.2s ease;
    }
    .export-btn-pill:hover {
        transform: translateY(-1px);
    }
    .emp-card-container {
        background: #ffffff;
        border-radius: 14px;
        border: 1px solid #e2e8f0;
        box-shadow: 0 4px 20px rgba(0, 0, 0, 0.04);
        overflow: hidden;
    }
    .table-responsive {
        overflow-x: auto;
    }
    .table-senior {
        margin-bottom: 0;
        width: 100%;
        border-collapse: separate;
        border-spacing: 0;
    }
    .table-senior thead th {
        background-color: #f8fafc;
        color: #475569;
        font-weight: 700;
        font-size: 0.74rem;
        text-transform: uppercase;
        letter-spacing: 0.6px;
        white-space: nowrap;
        border-bottom: 1px solid #e2e8f0;
        vertical-align: middle;
        text-align: center;
        padding: 12px 14px;
    }
    .table-senior tbody td {
        font-size: 0.86rem;
        vertical-align: middle;
        white-space: nowrap;
        color: #334155;
        padding: 12px 14px;
        text-align: center;
        border-bottom: 1px solid #f1f5f9;
        transition: background-color 0.15s ease;
    }
    .table-senior tbody tr:hover td {
        background-color: #f8fafc;
    }
    .text-left-custom {
        text-align: left !important;
    }
    .toggle-btn {
        background: #ffffff;
        border: 1px solid #cbd5e1;
        border-radius: 6px;
        width: 30px;
        height: 30px;
        padding: 0;
        color: #64748b;
        cursor: pointer;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        transition: all 0.2s ease;
    }
    .toggle-btn:hover {
        background-color: #10b981;
        color: #ffffff;
        border-color: #10b981;
    }
    .toggle-btn.active {
        background-color: #10b981;
        color: #ffffff;
        border-color: #10b981;
    }
    .toggle-icon {
        font-size: 11px;
        transition: transform 0.25s cubic-bezier(0.4, 0, 0.2, 1);
    }
    .toggle-icon.rotated {
        transform: rotate(90deg);
    }
    .emp-code-pill {
        font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace;
        font-weight: 700;
        font-size: 0.8rem;
        color: #059669;
        background: #d1fae5;
        padding: 3px 8px;
        border-radius: 6px;
        border: 1px solid #a7f3d0;
    }
    .status-badge-pill {
        display: inline-flex;
        align-items: center;
        gap: 5px;
        padding: 4px 10px;
        border-radius: 20px;
        font-size: 0.74rem;
        font-weight: 600;
    }
    .status-badge-pill.active {
        background: #dcfce7;
        color: #15803d;
    }
    .status-badge-pill.inactive {
        background: #fee2e2;
        color: #b91c1c;
    }
    .expanded-container {
        background: #f8fafc;
        border-left: 4px solid #10b981;
        padding: 22px 24px;
        text-align: left;
    }
    .detail-card-panel {
        background: #ffffff;
        border-radius: 10px;
        border: 1px solid #e2e8f0;
        padding: 18px 20px;
        height: 100%;
        box-shadow: 0 2px 6px rgba(0, 0, 0, 0.02);
    }
    .detail-heading {
        color: #0f172a;
        font-weight: 700;
        font-size: 0.88rem;
        text-transform: uppercase;
        letter-spacing: 0.5px;
        border-bottom: 1px solid #f1f5f9;
        padding-bottom: 10px;
        margin-bottom: 14px;
        display: flex;
        align-items: center;
        gap: 8px;
    }
    .detail-item {
        margin-bottom: 10px;
    }
    .detail-label-text {
        font-size: 0.72rem;
        color: #64748b;
        text-transform: uppercase;
        font-weight: 600;
        letter-spacing: 0.4px;
        margin-bottom: 2px;
    }
    .detail-value-text {
        font-size: 0.88rem;
        color: #1e293b;
        font-weight: 500;
        word-break: break-word;
    }
    .pagination-footer {
        background: #f8fafc;
        border-top: 1px solid #e2e8f0;
        padding: 14px 20px;
    }
    .collapse.show + tr > td {
        border-top: none;
    }
</style>

<div class="container-fluid main-content px-lg-4 px-md-3 px-2">
    
    <!-- Top Header Bar -->
    <div class="header-bar d-flex flex-wrap align-items-center justify-content-between gap-3 mb-2">
        <div>
            <a href="employee_master.php" class="back-btn-pill shadow-sm" title="Back to Employee Hub">
                <i class="fas fa-arrow-left"></i>
                <span>Back to List</span>
            </a>
        </div>
        <div class="text-center">
            <h4 class="page-main-heading">
                <i class="fas fa-briefcase text-success"></i>
                <span>Official Employee Info</span>
                <span class="badge bg-light text-secondary border fs-7 fw-normal ms-2" style="font-size: 0.75rem;"><?= $total_matching ?> Total</span>
            </h4>
        </div>
        <div>
            <a href="?export=true" class="btn btn-outline-success export-btn-pill shadow-sm" title="Export all official information as CSV">
                <i class="fas fa-file-csv fs-6"></i>
                <span>Export CSV</span>
            </a>
        </div>
    </div>

    <!-- Main Card & Data Table -->
    <div class="emp-card-container">
        <div class="table-responsive">
            <table class="table table-senior table-hover mb-0">
                <thead>
                    <tr>
                        <th style="width: 45px;"></th> 
                        <th style="width: 50px;">#</th>
                        <th>Emp ID</th>
                        <th class="text-left-custom">Name</th>
                        <th>DOJ</th>
                        <th>Salary (&#8377;)</th>
                        <th>TDS %</th>
                        <th>TDS Amount</th>
                        <th>Account No</th>
                        <th>IFSC Code</th>
                        <th>PAN No</th>
                        <th>UAN No</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    $query = "SELECT * FROM employee_master ORDER BY employee_id DESC LIMIT $offset, $results_per_page";
                    $run = $mysqli->query($query);
                    $i = $offset; 

                    if ($run && $run->num_rows > 0) {
                        while ($row = $run->fetch_assoc()) {
                            $i++;
                            $uniqueId = $row['employee_id'];
                            
                            // Data Prep (Preserving 100% of Original Data Processing)
                            $name       = htmlspecialchars($row['employee_name'] ?? '');
                            $emp_code   = htmlspecialchars($row['employee_username'] ?? '');
                            $doj        = ($row['joining_date'] && $row['joining_date'] != '0000-00-00') ? date('d-m-Y', strtotime($row['joining_date'])) : '-';
                            $desig      = htmlspecialchars($row['designation_id'] == 0 ? 'General' : $row['designation_id']);
                            $manager    = htmlspecialchars($row['reporting_manager'] ?? '-');
                            $shift      = htmlspecialchars($row['shift'] ?? 'General');
                            
                            // TDS LOGIC (Preserved exactly as original)
                            $raw_salary = isset($row['salary']) ? (float)$row['salary'] : 0;
                            $raw_tds_val = 0;
                            if (isset($row['tds_percentage']) && $row['tds_percentage'] > 0) { 
                                $raw_tds_val = (float)$row['tds_percentage']; 
                            } elseif (isset($row['tds_per']) && $row['tds_per'] > 0) { 
                                $raw_tds_val = (float)$row['tds_per']; 
                            }
                            if ($raw_tds_val == 0) {
                                $h_sql = "SELECT tds_per FROM employee_salary_history WHERE employee_id = '$uniqueId' ORDER BY history_id DESC LIMIT 1";
                                $h_run = $mysqli->query($h_sql);
                                if ($h_run && $h_run->num_rows > 0) { 
                                    $h_row = $h_run->fetch_assoc(); 
                                    $raw_tds_val = (float)$h_row['tds_per']; 
                                }
                            }
                            $calculated_tds_amt = ($raw_salary * $raw_tds_val) / 100;

                            $salary     = number_format($raw_salary, 2);
                            $tds_per    = $raw_tds_val . '%';
                            $tds_amt    = number_format($calculated_tds_amt, 2); 

                            $bank       = htmlspecialchars($row['bank_name'] ?? '-');
                            $branch     = htmlspecialchars($row['branch_name'] ?? '-');
                            $acc_no     = htmlspecialchars($row['account_no'] ?? '-');
                            $ifsc       = htmlspecialchars($row['ifsc_code'] ?? '-');
                            $pan_no     = htmlspecialchars($row['pan_no'] ?? '-');
                            $uan        = htmlspecialchars($row['uan_no'] ?? '-');
                            $esi        = htmlspecialchars($row['esi_no'] ?? '-');
                            $panImgPath = $row['pan_image_path'] ?? '';
                            $ppImgPath  = $row['passport_photo'] ?? '';
                            $statusStr  = $row['status'] ?? 'Active';
                            $statusClass = ($statusStr == 'Active') ? 'active' : 'inactive';

                            // Salary History (Preserved exactly as original)
                            $salary_log_json = $row['salary_log'] ?? '[]';
                            $salary_history = json_decode($salary_log_json, true);
                            if (!is_array($salary_history)) { $salary_history = []; }
                            usort($salary_history, function($a, $b) { return strtotime($b['date']) - strtotime($a['date']); });
                    ?>
                            <tr>
                                <td>
                                    <button class="toggle-btn" type="button" data-bs-toggle="collapse" data-bs-target="#details_<?= $uniqueId ?>" aria-expanded="false" aria-controls="details_<?= $uniqueId ?>" title="Click to view details">
                                        <i class="fas fa-chevron-right toggle-icon"></i>
                                    </button>
                                </td>
                                <td class="text-muted fw-semibold" style="font-size: 0.8rem;"><?= $i ?></td>
                                <td>
                                    <span class="emp-code-pill"><?= $emp_code ?></span>
                                </td>
                                <td class="text-left-custom fw-bold text-dark">
                                    <?= $name ?>
                                </td>
                                <td class="text-muted"><?= $doj ?></td>
                                <td class="fw-bold text-dark font-monospace"><?= $salary ?></td>
                                <td>
                                    <span class="badge bg-warning-subtle text-warning-emphasis border border-warning-subtle font-monospace px-2 py-1"><?= $tds_per ?></span>
                                </td>
                                <td class="text-danger fw-semibold font-monospace"><?= $tds_amt ?></td>
                                <td class="font-monospace text-secondary" style="font-size: 0.84rem;"><?= $acc_no ?></td>
                                <td class="font-monospace text-secondary" style="font-size: 0.84rem;"><?= $ifsc ?></td>
                                <td class="font-monospace text-dark" style="font-size: 0.84rem;"><?= $pan_no ?></td>
                                <td class="font-monospace text-secondary" style="font-size: 0.84rem;"><?= $uan ?></td>
                                <td>
                                    <span class="status-badge-pill <?= $statusClass ?>">
                                        <i class="fas fa-circle" style="font-size: 0.45rem;"></i>
                                        <?= $statusStr ?>
                                    </span>
                                </td>
                            </tr>

                            <!-- EXPANDED ROW (OFFICIAL ONLY - PRESERVED 100%) -->
                            <tr class="collapse" id="details_<?= $uniqueId ?>" data-bs-parent="tbody">
                                <td colspan="13" class="p-0">
                                    <div class="expanded-container">
                                        <!-- Header within expanded card -->
                                        <div class="d-flex align-items-center mb-4 border-bottom pb-3">
                                            <div class="me-3">
                                                <?php if (!empty($ppImgPath)): ?>
                                                    <img src="<?= $ppImgPath ?>" class="rounded-circle shadow-sm" style="width: 56px; height: 56px; object-fit: cover; border: 2px solid #10b981;">
                                                <?php else: ?>
                                                    <div class="rounded-circle text-white d-flex align-items-center justify-content-center shadow-sm" style="width: 56px; height: 56px; font-size: 20px; font-weight: 700; background: linear-gradient(135deg, #10b981, #047857);">
                                                        <?= strtoupper(substr($name, 0, 1)) ?>
                                                    </div>
                                                <?php endif; ?>
                                            </div>
                                            <div>
                                                <h5 class="mb-1 text-dark fw-bold"><?= $name ?></h5>
                                                <div class="d-flex align-items-center gap-2">
                                                    <span class="emp-code-pill" style="font-size: 0.72rem;">ID: <?= $emp_code ?></span>
                                                    <span class="text-muted small">Official & Banking Profile</span>
                                                </div>
                                            </div>
                                        </div>

                                        <div class="row g-3">
                                            <!-- Column 1: Employment Details -->
                                            <div class="col-md-6 col-12">
                                                <div class="detail-card-panel">
                                                    <div class="detail-heading">
                                                        <i class="fas fa-briefcase text-success"></i>
                                                        <span>Employment Details</span>
                                                    </div>
                                                    <div class="row g-2">
                                                        <div class="col-sm-6 col-12 detail-item">
                                                            <div class="detail-label-text">Designation</div>
                                                            <div class="detail-value-text"><?= $desig ?></div>
                                                        </div>
                                                        <div class="col-sm-6 col-12 detail-item">
                                                            <div class="detail-label-text">Shift</div>
                                                            <div class="detail-value-text"><span class="badge bg-light text-secondary border"><?= $shift ?></span></div>
                                                        </div>
                                                        <div class="col-12 detail-item">
                                                            <div class="detail-label-text">Reporting Manager</div>
                                                            <div class="detail-value-text"><?= $manager ?></div>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>

                                            <!-- Column 2: Banking & Statutory Details -->
                                            <div class="col-md-6 col-12">
                                                <div class="detail-card-panel">
                                                    <div class="detail-heading">
                                                        <i class="fas fa-building-columns text-primary"></i>
                                                        <span>Banking & Statutory</span>
                                                    </div>
                                                    <div class="row g-2">
                                                        <div class="col-sm-6 col-12 detail-item">
                                                            <div class="detail-label-text">Bank Name</div>
                                                            <div class="detail-value-text"><?= $bank ?></div>
                                                        </div>
                                                        <div class="col-sm-6 col-12 detail-item">
                                                            <div class="detail-label-text">Branch</div>
                                                            <div class="detail-value-text"><?= $branch ?></div>
                                                        </div>
                                                        <div class="col-sm-6 col-12 detail-item">
                                                            <div class="detail-label-text">Account No</div>
                                                            <div class="detail-value-text font-monospace"><?= $acc_no ?></div>
                                                        </div>
                                                        <div class="col-sm-6 col-12 detail-item">
                                                            <div class="detail-label-text">IFSC Code</div>
                                                            <div class="detail-value-text font-monospace"><?= $ifsc ?></div>
                                                        </div>
                                                        <div class="col-sm-6 col-12 detail-item">
                                                            <div class="detail-label-text">ESI Number</div>
                                                            <div class="detail-value-text font-monospace"><?= $esi ?></div>
                                                        </div>
                                                        <div class="col-sm-6 col-12 detail-item">
                                                            <div class="detail-label-text">PAN Document</div>
                                                            <div class="detail-value-text">
                                                                <?php if (!empty($panImgPath)): ?>
                                                                    <a href="<?= $panImgPath ?>" target="_blank" class="badge bg-light text-primary border text-decoration-none">
                                                                        <i class="fas fa-file-pdf text-danger me-1"></i>View PAN
                                                                    </a>
                                                                <?php else: ?>
                                                                    <span class="text-muted">-</span>
                                                                <?php endif; ?>
                                                            </div>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>

                                            <!-- Column 3: Salary History Table (Full Width) -->
                                            <div class="col-12">
                                                <div class="detail-card-panel">
                                                    <div class="detail-heading">
                                                        <i class="fas fa-clock-rotate-left text-warning"></i>
                                                        <span>Salary History</span>
                                                    </div>
                                                    <?php if (!empty($salary_history)): ?>
                                                        <div class="table-responsive" style="max-height: 160px; overflow-y: auto;">
                                                            <table class="table table-sm table-bordered mb-0 text-center" style="font-size: 0.82rem;">
                                                                <thead class="table-light">
                                                                    <tr>
                                                                        <th>Date</th>
                                                                        <th>Previous Salary</th>
                                                                        <th>Revised Salary</th>
                                                                    </tr>
                                                                </thead>
                                                                <tbody>
                                                                    <?php foreach ($salary_history as $hist): ?>
                                                                    <tr>
                                                                        <td><?= date('d-M-Y', strtotime($hist['date'])) ?></td>
                                                                        <td class="text-muted font-monospace">&#8377;<?= number_format((float)($hist['old'] ?? 0), 2) ?></td>
                                                                        <td class="text-success fw-bold font-monospace">&#8377;<?= number_format((float)($hist['new'] ?? 0), 2) ?></td>
                                                                    </tr>
                                                                    <?php endforeach; ?>
                                                                </tbody>
                                                            </table>
                                                        </div>
                                                    <?php else: ?>
                                                        <span class="small text-muted"><i class="fas fa-info-circle me-1"></i>No previous salary revisions on record.</span>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                        </div>

                                    </div>
                                </td>
                            </tr>
                    <?php
                        }
                    } else {
                        echo "<tr><td colspan='13' class='text-center py-5 text-muted'><i class='fas fa-user-slash fa-2x mb-2 d-block text-secondary opacity-50'></i>No records found</td></tr>";
                    }
                    ?>
                </tbody>
            </table>
        </div>

        <!-- Pagination Controls -->
        <?php if ($total_pages > 1): ?>
        <div class="pagination-footer d-flex flex-wrap align-items-center justify-content-between gap-2">
            <div class="text-muted small">
                Showing <span class="fw-semibold"><?= min($offset + 1, $total_matching) ?></span> to <span class="fw-semibold"><?= min($offset + $results_per_page, $total_matching) ?></span> of <span class="fw-semibold"><?= $total_matching ?></span> employees
            </div>
            <nav aria-label="Page navigation">
                <ul class="pagination pagination-sm mb-0">
                    <li class="page-item <?= ($page <= 1) ? 'disabled' : '' ?>">
                        <a class="page-link" href="?page=<?= $page - 1 ?>" style="border-radius: 6px 0 0 6px;">Previous</a>
                    </li>
                    <?php for ($p = 1; $p <= $total_pages; $p++): ?>
                        <li class="page-item <?= ($page == $p) ? 'active' : '' ?>">
                            <a class="page-link" href="?page=<?= $p ?>"><?= $p ?></a>
                        </li>
                    <?php endfor; ?>
                    <li class="page-item <?= ($page >= $total_pages) ? 'disabled' : '' ?>">
                        <a class="page-link" href="?page=<?= $page + 1 ?>" style="border-radius: 0 6px 6px 0;">Next</a>
                    </li>
                </ul>
            </nav>
        </div>
        <?php endif; ?>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function() {
    // Smooth toggle animation for chevron
    const collapseElements = document.querySelectorAll('.collapse');
    collapseElements.forEach(collapse => {
        collapse.addEventListener('show.bs.collapse', function() {
            const targetId = this.id;
            const button = document.querySelector(`[data-bs-target="#${targetId}"]`);
            if (button) {
                const icon = button.querySelector('.toggle-icon');
                button.classList.add('active');
                if (icon) icon.classList.add('rotated');
            }
        });
        
        collapse.addEventListener('hide.bs.collapse', function() {
            const targetId = this.id;
            const button = document.querySelector(`[data-bs-target="#${targetId}"]`);
            if (button) {
                const icon = button.querySelector('.toggle-icon');
                button.classList.remove('active');
                if (icon) icon.classList.remove('rotated');
            }
        });
    });
});
</script>
