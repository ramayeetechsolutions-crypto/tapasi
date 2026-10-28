<?php
// -------------------------------------------------------------
// FILE: employee_official_info.php
// PURPOSE: Displays ONLY Official/Salary Information
// -------------------------------------------------------------

require 'db.php';
ini_set('display_errors', 1);
error_reporting(E_ALL);

if (session_status() === PHP_SESSION_NONE) { session_start(); }

// --- EXPORT LOGIC (OFFICIAL INFO ONLY) ---
if (isset($_GET['export'])) {
    error_reporting(0); 
    ini_set('display_errors', 0);
    if (ob_get_level()) { ob_end_clean(); }

    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="official_info_'.date('Y-m-d').'.csv"');
    
    $output = fopen('php://output', 'w');
    
    // Header - Official Columns Only
    fputcsv($output, array('Employee ID', 'Name', 'DOJ', 'Designation', 'Shift', 'Salary', 'Bank', 'Account No', 'IFSC', 'PAN', 'UAN', 'Status'), ",", "\"", "\\");
    
    $exp_query = "SELECT * FROM employee_master1 ORDER BY employee_id DESC";
    $exp_run = $mysqli->query($exp_query);
    
    while($row = $exp_run->fetch_assoc()) {
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
include 'header.php'; 

// --- PAGINATION ---
$results_per_page = 10; 
$page = (isset($_GET['page']) && is_numeric($_GET['page'])) ? (int) $_GET['page'] : 1;
$offset = ($page - 1) * $results_per_page;

$count_query = "SELECT COUNT(*) AS total FROM employee_master1";
$count_result = $mysqli->query($count_query);
$count_row = $count_result->fetch_assoc();
$total_pages = ceil($count_row['total'] / $results_per_page);
?>

<style>
    .main-content { margin-top: 60px; }
    .table-responsive { overflow-x: auto; }
    .table thead th { background-color: #f8f9fa; color: #495057; font-weight: 600; font-size: 0.85rem; white-space: nowrap; border-bottom: 2px solid #dee2e6; vertical-align: middle; text-align: center; }
    .table tbody td { font-size: 0.9rem; vertical-align: middle; white-space: nowrap; color: #333; padding: 10px 15px; text-align: center; }
    .text-left-custom { text-align: left !important; }
    .toggle-btn { background: transparent; border: 1px solid #dee2e6; border-radius: 4px; padding: 2px 8px; color: #6c757d; cursor: pointer; }
    .toggle-btn:hover { background-color: #0d6efd; color: white; }
    .toggle-icon { font-size: 12px; transition: transform 0.3s; }
    .badge-active { background-color: #198754; color: white; padding: 5px 10px; border-radius: 4px; font-size: 0.8rem; }
    .badge-inactive { background-color: #dc3545; color: white; padding: 5px 10px; border-radius: 4px; font-size: 0.8rem; }
    .img-icon { width: 28px; height: 28px; object-fit: contain; }
    .expanded-row { background-color: #f8f9fa; border-left: 5px solid #198754; text-align: left; padding: 20px; } /* Green border for official */
    .detail-section-title { color: #198754; font-weight: 700; font-size: 0.95rem; text-transform: uppercase; border-bottom: 1px solid #e9ecef; padding-bottom: 8px; margin-bottom: 15px; margin-top: 10px; }
    .detail-label { font-size: 0.75rem; color: #6c757d; text-transform: uppercase; font-weight: 600; margin-bottom: 2px; }
    .detail-value { font-size: 0.95rem; color: #212529; font-weight: 500; word-wrap: break-word; white-space: normal; }
    .doc-preview { border: 1px solid #ddd; padding: 4px; background: #fff; border-radius: 4px; max-height: 80px; max-width: 120px; }
</style>

<div class="container-fluid main-content py-4">
    <div class="d-flex justify-content-between align-items-center mb-3">
      <h4 class="text-secondary"><i class="fa fa-briefcase"></i> Official Employee Info</h4>
      <div>
          <a href="?export=true" class="btn btn-outline-success shadow-sm me-2"><i class="fa fa-download"></i> Export CSV</a>
      </div>
    </div>

    <div class="card border-0 shadow-sm rounded-3 overflow-hidden">
      <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead>
                <tr>
                    <th style="width: 50px;"></th> 
                    <th>#</th>
                    <th>Emp ID</th>
                    <th class="text-left-custom">Name</th>
                    <th>DOJ</th>
                    <th>Salary (₹)</th>
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
                    $query = "SELECT * FROM employee_master1 ORDER BY employee_id DESC LIMIT $offset, $results_per_page";
                    $run = $mysqli->query($query);
                    $i = $offset; 

                    if($run && $run->num_rows > 0){
                        while($row = $run->fetch_assoc()){
                            $i++;
                            $uniqueId = $row['employee_id'];
                            
                            $name       = htmlspecialchars($row['employee_name'] ?? '');
                            $emp_code   = htmlspecialchars($row['employee_username'] ?? '');
                            $doj  = ($row['joining_date'] && $row['joining_date'] != '0000-00-00') ? date('d-m-Y', strtotime($row['joining_date'])) : '-';
                            $desig      = htmlspecialchars($row['designation_id'] == 0 ? 'General' : $row['designation_id']);
                            $manager    = htmlspecialchars($row['reporting_manager'] ?? '-');
                            $shift      = htmlspecialchars($row['shift'] ?? 'General');
                            
                            // TDS LOGIC
                            $raw_salary = isset($row['salary']) ? (float)$row['salary'] : 0;
                            $raw_tds_val = 0;
                            if (isset($row['tds_percentage']) && $row['tds_percentage'] > 0) { $raw_tds_val = (float)$row['tds_percentage']; } 
                            elseif (isset($row['tds_per']) && $row['tds_per'] > 0) { $raw_tds_val = (float)$row['tds_per']; }
                            if ($raw_tds_val == 0) {
                                $h_sql = "SELECT tds_per FROM employee_salary_history WHERE employee_id = '$uniqueId' ORDER BY history_id DESC LIMIT 1";
                                $h_run = $mysqli->query($h_sql);
                                if($h_run && $h_run->num_rows > 0){ $h_row = $h_run->fetch_assoc(); $raw_tds_val = (float)$h_row['tds_per']; }
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
                            $statusClass = ($statusStr == 'Active') ? 'badge-active' : 'badge-inactive';

                            // Salary History
                            $salary_log_json = $row['salary_log'] ?? '[]';
                            $salary_history = json_decode($salary_log_json, true);
                            if (!is_array($salary_history)) { $salary_history = []; }
                            usort($salary_history, function($a, $b) { return strtotime($b['date']) - strtotime($a['date']); });
                            ?>
                            
                            <tr>
                                <td>
                                    <button class="toggle-btn" type="button" data-bs-toggle="collapse" data-bs-target="#details_<?= $uniqueId ?>" onclick="toggleArrow(this)">
                                        <i class="fa fa-chevron-right toggle-icon"></i>
                                    </button>
                                </td>
                                <td class="text-muted"><?= $i ?></td>
                                <td class="fw-bold text-success"><?= $emp_code ?></td>
                                <td class="text-left-custom fw-bold"><?= $name ?></td>
                                <td><?= $doj ?></td>
                                <td><?= $salary ?></td>
                                <td><span class="badge bg-warning text-dark"><?= $tds_per ?></span></td>
                                <td class="text-danger"><?= $tds_amt ?></td>
                                <td><?= $acc_no ?></td>
                                <td><?= $ifsc ?></td>
                                <td><?= $pan_no ?></td>
                                <td><?= $uan ?></td>
                                <td><span class="<?= $statusClass ?>"><?= $statusStr ?></span></td>
                            </tr>

                            <!-- EXPANDED ROW (OFFICIAL ONLY) -->
                            <tr class="collapse fade" id="details_<?= $uniqueId ?>">
                                <td colspan="13" class="p-0">
                                    <div class="expanded-row">
                                        <!-- Header -->
                                        <div class="d-flex align-items-center mb-4 border-bottom pb-3">
                                            <div class="me-3">
                                                <?php if(!empty($ppImgPath)): ?>
                                                    <img src="<?= $ppImgPath ?>" class="rounded-circle" style="width: 60px; height: 60px; object-fit: cover; border: 2px solid #198754;">
                                                <?php else: ?>
                                                    <div class="rounded-circle bg-secondary text-white d-flex align-items-center justify-content-center" style="width: 60px; height: 60px; font-size: 24px;">
                                                        <?= strtoupper(substr($name,0,1)) ?>
                                                    </div>
                                                <?php endif; ?>
                                            </div>
                                            <div>
                                                <h4 class="mb-0 text-dark"><?= $name ?></h4>
                                                <span class="text-muted small">ID: <strong><?= $emp_code ?></strong></span>
                                            </div>
                                        </div>

                                        <div class="row g-4">
                                            <!-- Employment -->
                                            <div class="col-md-6">
                                                <div class="p-3 bg-white rounded border h-100">
                                                    <div class="detail-section-title"><i class="fa fa-briefcase me-1"></i> Employment</div>
                                                    <div class="mb-2"><div class="detail-label">Designation</div><div class="detail-value"><?= $desig ?></div></div>
                                                    <div class="mb-2"><div class="detail-label">Manager</div><div class="detail-value"><?= $manager ?></div></div>
                                                    <div class="mb-2"><div class="detail-label">Shift</div><div class="detail-value"><?= $shift ?></div></div>
                                                    <div class="mb-2"><div class="detail-label">Joining Date</div><div class="detail-value"><?= $doj ?></div></div>
                                                    
                                                    <div class="detail-section-title mt-3"><i class="fa fa-file-contract me-1"></i> Statutory</div>
                                                    <div class="mb-2"><div class="detail-label">UAN No</div><div class="detail-value"><?= $uan ?></div></div>
                                                    <div class="mb-2"><div class="detail-label">ESI No</div><div class="detail-value"><?= $esi ?></div></div>
                                                </div>
                                            </div>
                                            <!-- Banking & Docs -->
                                            <div class="col-md-6">
                                                <div class="p-3 bg-white rounded border h-100">
                                                    <div class="detail-section-title"><i class="fa fa-university me-1"></i> Banking</div>
                                                    <div class="mb-2"><div class="detail-label">Bank Name</div><div class="detail-value"><?= $bank ?></div></div>
                                                    <div class="mb-2"><div class="detail-label">Branch</div><div class="detail-value"><?= $branch ?></div></div>
                                                    <div class="mb-2"><div class="detail-label">Account No</div><div class="detail-value"><?= $acc_no ?></div></div>
                                                    <div class="mb-2"><div class="detail-label">IFSC Code</div><div class="detail-value"><?= $ifsc ?></div></div>

                                                    <div class="detail-section-title mt-3"><i class="fa fa-paperclip me-1"></i> PAN Doc</div>
                                                    <?php if(!empty($panImgPath)): ?><a href="<?= $panImgPath ?>" target="_blank" class="doc-preview text-center text-decoration-none"><small>View PAN</small></a><?php else: ?><span class="text-muted small">No Document</span><?php endif; ?>
                                                </div>
                                            </div>
                                            <!-- Salary History -->
                                            <div class="col-12">
                                                <div class="p-3 bg-white rounded border">
                                                    <div class="detail-section-title"><i class="fa fa-history me-1"></i> Salary History</div>
                                                    <?php if (!empty($salary_history)): ?>
                                                        <div class="table-responsive" style="max-height: 150px; overflow-y:auto;">
                                                            <table class="table table-sm table-bordered mb-0 text-center" style="font-size:0.8rem;">
                                                                <thead class="table-light"><tr><th>Date</th><th>Old Salary</th><th>New Salary</th></tr></thead>
                                                                <tbody>
                                                                    <?php foreach($salary_history as $hist): ?>
                                                                    <tr><td><?= date('d-M-y', strtotime($hist['date'])) ?></td><td>₹<?= number_format($hist['old']) ?></td><td class="text-success fw-bold">₹<?= number_format($hist['new']) ?></td></tr>
                                                                    <?php endforeach; ?>
                                                                </tbody>
                                                            </table>
                                                        </div>
                                                    <?php else: ?><span class="small text-muted">No history found.</span><?php endif; ?>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </td>
                            </tr>
                            <?php
                        }
                    } else {
                        echo "<tr><td colspan='13' class='text-center py-5 text-muted'>No records found</td></tr>";
                    }
                    ?>
                </tbody>
            </table>
        </div>
      </div>

      <!-- Pagination Code -->
      <?php if ($total_pages > 1): ?>
      <div class="card-footer bg-light p-3">
          <nav aria-label="Page navigation">
            <ul class="pagination justify-content-end mb-0">
                <li class="page-item <?= ($page <= 1) ? 'disabled' : '' ?>"><a class="page-link" href="?page=<?= $page - 1 ?>">Previous</a></li>
                <?php for($p = 1; $p <= $total_pages; $p++): ?>
                    <li class="page-item <?= ($page == $p) ? 'active' : '' ?>"><a class="page-link" href="?page=<?= $p ?>"><?= $p ?></a></li>
                <?php endfor; ?>
                <li class="page-item <?= ($page >= $total_pages) ? 'disabled' : '' ?>"><a class="page-link" href="?page=<?= $page + 1 ?>">Next</a></li>
            </ul>
          </nav>
      </div>
      <?php endif; ?>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
    function toggleArrow(btn) {
        var icon = btn.querySelector('.toggle-icon');
        icon.style.transform = (icon.style.transform === "rotate(90deg)") ? "rotate(0deg)" : "rotate(90deg)";
    }
</script>