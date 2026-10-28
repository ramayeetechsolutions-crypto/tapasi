<?php
require 'db.php'; 
if (session_status() === PHP_SESSION_NONE) { session_start(); }

$msg = "";
$error = "";

// --- 1. DELETE LOGIC ---
if (isset($_GET['delete_id'])) {
    $id = (int)$_GET['delete_id'];
    if ($mysqli->query("DELETE FROM sales_calculations WHERE id = $id")) {
        $msg = "Record deleted successfully!";
    }
}

// --- 2. SAVE/UPDATE LOGIC ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_sales'])) {
    $id         = isset($_POST['edit_id']) ? (int)$_POST['edit_id'] : 0;
    $service    = $mysqli->real_escape_string($_POST['service_id']);
    $date       = $mysqli->real_escape_string($_POST['sales_date']);
    $monthly    = (float)$_POST['amt_monthly'];
    $bimonthly  = (float)$_POST['amt_bimonthly'];
    $triannual  = (float)$_POST['amt_triannual']; // Added
    $quarterly  = (float)$_POST['amt_quarterly'];
    $biannual   = (float)$_POST['amt_biannually'];
    $annual     = (float)$_POST['amt_annually'];

    if ($id > 0) {
        $sql = "UPDATE sales_calculations SET 
                service_identifier='$service', sales_date='$date', amt_monthly='$monthly', 
                amt_bimonthly='$bimonthly', amt_triannual='$triannual', amt_quarterly='$quarterly', 
                amt_biannually='$biannual', amt_annually='$annual' 
                WHERE id=$id";
    } else {
        $sql = "INSERT INTO sales_calculations 
                (service_identifier, sales_date, amt_monthly, amt_bimonthly, amt_triannual, amt_quarterly, amt_biannually, amt_annually) 
                VALUES ('$service', '$date', '$monthly', '$bimonthly', '$triannual', '$quarterly', '$biannual', '$annual')";
    }

    if ($mysqli->query($sql)) { 
        $msg = "Success!"; 
    } else { 
        $error = "Error: " . $mysqli->error; 
    }
}

// --- 3. FETCH DATA FOR EDITING ---
$edit_data = null;
if (isset($_GET['edit_id'])) {
    $eid = (int)$_GET['edit_id'];
    $edit_res = $mysqli->query("SELECT * FROM sales_calculations WHERE id = $eid");
    $edit_data = $edit_res->fetch_assoc();
}

// --- 4. FETCH JOBS & RECORDS ---
$jobs_query = $mysqli->query("SELECT * FROM job_master WHERE status = 'Active' ORDER BY job_name ASC");
$jobs = $jobs_query->fetch_all(MYSQLI_ASSOC);

$records_sql = "SELECT s.*, j.job_name 
                FROM sales_calculations s 
                LEFT JOIN job_master j ON s.service_identifier = j.job_id 
                ORDER BY s.sales_date DESC";
$records = $mysqli->query($records_sql);

require 'header.php'; 
?>
<style>
    .table thead th {
        background-color: #6c757d !important;
        color: white !important;
        border-bottom: none !important;
    }
</style>

<div class="container-fluid py-4" style="margin-top: 60px;">
    <?php if($msg): ?> <div class="alert alert-success"><?= $msg ?></div> <?php endif; ?>

    <div class="row">
        <!-- FORM COLUMN -->
         <h3 class="mb-0"><i class="fas fa-calculator me-2"></i>Sales Calculation</h3>
        <div class="col-lg-4">
            <div class="card shadow mb-4">
                              <div class="card-body">
                    <form method="post">
                        <input type="hidden" name="edit_id" value="<?= $edit_data['id'] ?? '' ?>">
                        
                        <div class="mb-3">
                            <label class="form-label fw-bold">Service / Job</label>
                            <select name="service_id" id="service_id" class="form-select" required>
                                <option value="" disabled <?= !$edit_data ? 'selected' : '' ?>>---Select Service---</option>
                                <?php 
                                foreach($jobs as $row){
                                    $sel = ($edit_data && $edit_data['service_identifier'] == $row['job_id']) ? 'selected' : '';
                                    echo '<option value="'.$row['job_id'].'" data-name="'.htmlspecialchars($row['job_name']).'" '.$sel.'>'.htmlspecialchars($row['job_name']).'</option>';
                                }
                                ?>
                                <option value="One time Conference-Rs500/-" data-name="One time Conference" <?= ($edit_data && $edit_data['service_identifier'] == 'One time Conference-Rs500/-') ? 'selected' : '' ?>>One time Conference-Rs500/-</option>
                                <option value="Book Count- Rs1000/-" data-name="Book Count" <?= ($edit_data && $edit_data['service_identifier'] == 'Book Count- Rs1000/-') ? 'selected' : '' ?>>Book Count- Rs1000/-</option>
                            </select>
                        </div>

                        <div class="mb-3">
                            <label class="form-label fw-bold">Date</label>
                            <input type="date" name="sales_date" class="form-control" value="<?= $edit_data['sales_date'] ?? date('Y-m-d') ?>" required>
                        </div>

                        <div class="mb-3">
                            <label class="form-label fw-bold text-success">Monthly (Base)</label>
                            <input type="number" step="0.01" name="amt_monthly" id="amt_monthly" class="form-control fw-bold" value="<?= $edit_data['amt_monthly'] ?? '' ?>" required>
                        </div>

                        <!-- CALCULATED FIELDS -->
                        <div class="row g-2">
                            <div class="col-6 mb-2">
                                <label class="small fw-bold">Bi-mon (/2)</label>
                                <input type="number" name="amt_bimonthly" id="amt_bimonthly" class="form-control bg-light" value="<?= $edit_data['amt_bimonthly'] ?? '' ?>" readonly>
                            </div>
                            <div class="col-6 mb-2">
                                <label class="small fw-bold text-danger">Tri-ann (/4)</label>
                                <input type="number" name="amt_triannual" id="amt_triannual" class="form-control bg-light" value="<?= $edit_data['amt_triannual'] ?? '' ?>" readonly>
                            </div>
                            <div class="col-6 mb-2">
                                <label class="small fw-bold">Quart (/3)</label>
                                <input type="number" name="amt_quarterly" id="amt_quarterly" class="form-control bg-light" value="<?= $edit_data['amt_quarterly'] ?? '' ?>" readonly>
                            </div>
                            <div class="col-6 mb-2">
                                <label class="small fw-bold">Bi-ann (/6)</label>
                                <input type="number" name="amt_biannually" id="amt_biannually" class="form-control bg-light" value="<?= $edit_data['amt_biannually'] ?? '' ?>" readonly>
                            </div>
                            <div class="col-12">
                                <label class="small fw-bold text-primary">Annual (/12)</label>
                                <input type="number" name="amt_annually" id="amt_annually" class="form-control bg-light" value="<?= $edit_data['amt_annually'] ?? '' ?>" readonly>
                            </div>
                        </div>

                        <button type="submit" name="save_sales" class="btn btn-primary w-100 mt-3 fw-bold">Save Calculation</button>
                    </form>
                </div>
            </div>
        </div>

        <!-- GRID COLUMN -->
        <div class="col-lg-8">
            <div class="card shadow">
                <div class="card-header bg-dark text-white">
                    <h5 class="mb-0"><i class="fas fa-list me-2"></i> Saved Calculations</h5>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0" style="font-size: 0.85rem;">
                            <thead>
                                <tr>
                                    <th>Date</th>
                                    <th>Service Name</th>
                                    <th>Mon</th>
                                    <th>Bi-M</th>
                                    <th>Tri-A</th>
                                    <th>Quart</th>
                                    <th>Bi-A</th>
                                    <th>Annual</th>
                                    <th>Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php while($row = $records->fetch_assoc()): 
                                    $displayName = !empty($row['job_name']) ? $row['job_name'] : $row['service_identifier'];
                                ?>
                                <tr>
                                    <td><?= $row['sales_date'] ?></td>
                                    <td class="fw-bold"><?= htmlspecialchars($displayName) ?></td>
                                    <td class="fw-bold">$<?= number_format($row['amt_monthly']) ?></td>
                                    <td>$<?= number_format($row['amt_bimonthly']) ?></td>
                                    <td class="text-danger fw-bold">$<?= number_format($row['amt_triannual']) ?></td>
                                    <td>$<?= number_format($row['amt_quarterly']) ?></td>
                                    <td>$<?= number_format($row['amt_biannually']) ?></td>
                                    <td class="text-success fw-bold">$<?= number_format($row['amt_annually']) ?></td>
                                    <td>
                                        <div class="btn-group">
                                            <a href="?edit_id=<?= $row['id'] ?>" class="btn btn-sm btn-outline-info p-1"><i class="fas fa-edit"></i></a>
                                            <a href="?delete_id=<?= $row['id'] ?>" class="btn btn-sm btn-outline-danger p-1" onclick="return confirm('Delete?')"><i class="fas fa-trash"></i></a>
                                        </div>
                                    </td>
                                </tr>
                                <?php endwhile; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const serviceSelect = document.getElementById('service_id');
    const inputMonthly   = document.getElementById('amt_monthly');
    const inputBi        = document.getElementById('amt_bimonthly');
    const inputTri       = document.getElementById('amt_triannual'); // Added
    const inputQuarterly = document.getElementById('amt_quarterly');
    const inputBiannual  = document.getElementById('amt_biannually');
    const inputAnnual    = document.getElementById('amt_annually');

    function calculateBreakdown() {
        let monthly = parseFloat(inputMonthly.value);
        if (isNaN(monthly) || monthly <= 0) {
            inputBi.value = inputTri.value = inputQuarterly.value = inputBiannual.value = inputAnnual.value = "";
            return;
        }
        inputBi.value        = Math.round(monthly / 2);
        inputTri.value       = Math.round(monthly / 4); // Monthly / 4
        inputQuarterly.value = Math.round(monthly / 3);
        inputBiannual.value  = Math.round(monthly / 6);
        inputAnnual.value    = Math.round(monthly / 12);
    }

    serviceSelect.addEventListener('change', function() {
        const opt = this.options[this.selectedIndex];
        const val = opt.value;
        const name = opt.getAttribute('data-name').toUpperCase();
        
        let price = 0;
        if (val.includes("Conference")) price = 500;
        else if (val.includes("Book Count")) price = 1000;
        else if (name.includes("CE+TS+XML")) price = 10000;
        else if (name.includes("CE+TS")) price = 8000;
        else if (name.includes("TS")) price = 6000;
        else if (name.includes("XML") || name.includes("CE")) price = 2000;

        if(price > 0) {
            inputMonthly.value = price;
            calculateBreakdown();
        }
    });

    inputMonthly.addEventListener('input', calculateBreakdown);
});
</script>