<?php
require 'db.php'; // Ensure this points to your actual database connection file
if (session_status() === PHP_SESSION_NONE) { session_start(); }

// 1. SAVE LOGIC
$msg = "";
$error = "";
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_sales'])) {
    // Sanitize Inputs
    $service    = $mysqli->real_escape_string($_POST['service_id']);
    $date       = $mysqli->real_escape_string($_POST['sales_date']);
    
    // Get Amounts
    $monthly    = (float)$_POST['amt_monthly'];
    $bimonthly  = (float)$_POST['amt_bimonthly'];
    $quarterly  = (float)$_POST['amt_quarterly'];
    $biannual   = (float)$_POST['amt_biannually'];

    // Insert Query
    $sql = "INSERT INTO sales_calculations 
            (service_identifier, sales_date, amt_monthly, amt_bimonthly, amt_quarterly, amt_biannually) 
            VALUES 
            ('$service', '$date', '$monthly', '$bimonthly', '$quarterly', '$biannual')";

    if($mysqli->query($sql)) { 
        $msg = "Sales calculation saved successfully!"; 
    } else { 
        $error = "Error: " . $mysqli->error; 
    }
}

// 2. FETCH JOBS FROM DB
$jobs = $mysqli->query("SELECT * FROM job_master WHERE status = 'Active' ORDER BY job_name ASC");

require 'header.php'; 
?>

<!-- Main Content Container with Top Margin to clear fixed header -->
<div class="container-fluid py-4" style="margin-top: 60px;">

    <!-- Messages -->
    <?php if($msg): ?> <div class="alert alert-success"><?= $msg ?></div> <?php endif; ?>
    <?php if($error): ?> <div class="alert alert-danger"><?= $error ?></div> <?php endif; ?>

    <div class="row justify-content-center">
        <div class="col-md-10 col-lg-8"> <!-- Widened card to fit 4 columns -->
            <div class="card shadow">
                <div class="card-header bg-primary text-white py-3">
                    <h5 class="mb-0"><i class="fas fa-calculator me-2"></i>Sales & OT Calculation</h5>
                </div>
                <div class="card-body p-4">
                    <form method="post">

                        <div class="row">
                            <!-- 1. SERVICE DROPDOWN -->
                            <div class="col-md-6 mb-3">
                                <label class="form-label fw-bold">Service / Job</label>
                                <select name="service_id" id="service_id" class="form-select" required>
                                    <option value="" selected disabled>---Select Service---</option>
                                    <?php 
                                    if($jobs->num_rows > 0){
                                        while($row = $jobs->fetch_assoc()){
                                            // Using job_id and job_name, putting name in data-name for JS
                                            echo '<option value="'.$row['job_id'].'" data-name="'.htmlspecialchars($row['job_name']).'">'.htmlspecialchars($row['job_name']).'</option>';
                                        }
                                        echo '<option value="One time Conference-Rs500/-" data-name="One time Conference">One time Conference-Rs500/-</option>';
                                        echo '<option value="Book Count- Rs1000/-" data-name="Book Count">Book Count- Rs1000/-</option>';
                                    }
                                    ?>
                                </select>
                            </div>

                            <!-- 2. DATE PICKER -->
                            <div class="col-md-6 mb-3">
                                <label class="form-label fw-bold">Date</label>
                                <input type="date" name="sales_date" class="form-control" value="<?= date('Y-m-d') ?>" required>
                            </div>
                        </div>

                        <hr class="my-4">
                        
                        <!-- 3. CALCULATED AMOUNTS SECTION -->
                        <h6 class="fw-bold text-secondary mb-3"><i class="fas fa-coins me-2"></i>Frequency Breakdown (New Rate)</h6>
                        
                        <div class="row g-3">
                            <!-- Monthly (Base Input) -->
                            <div class="col-md-3">
                                <label class="form-label fw-bold text-success">Monthly</label>
                                <div class="input-group">
                                    <span class="input-group-text">$</span>
                                    <!-- This is the 'Typable' Base Field -->
                                    <input type="number" step="0.01" name="amt_monthly" id="amt_monthly" class="form-control fw-bold" placeholder="0.00" required>
                                </div>
                                <div class="form-text small">Base Amount</div>
                            </div>

                            <!-- Bi-monthly (Auto) -->
                            <div class="col-md-3">
                                <label class="form-label fw-bold">Bi-monthly</label>
                                <div class="input-group">
                                    <span class="input-group-text">$</span>
                                    <input type="number" name="amt_bimonthly" id="amt_bimonthly" class="form-control bg-light" placeholder="0" readonly>
                                </div>
                                <div class="form-text small">Monthly / 2</div>
                            </div>

                            <!-- Quarterly (Auto) -->
                            <div class="col-md-3">
                                <label class="form-label fw-bold">Quarterly</label>
                                <div class="input-group">
                                    <span class="input-group-text">$</span>
                                    <input type="number" name="amt_quarterly" id="amt_quarterly" class="form-control bg-light" placeholder="0" readonly>
                                </div>
                                <div class="form-text small">Monthly / 4</div>
                            </div>

                            <!-- Bi-annually (Auto) -->
                            <div class="col-md-3">
                                <label class="form-label fw-bold">Bi-annually</label>
                                <div class="input-group">
                                    <span class="input-group-text">$</span>
                                    <input type="number" name="amt_biannually" id="amt_biannually" class="form-control bg-light" placeholder="0" readonly>
                                </div>
                                <div class="form-text small">Monthly / 6</div>
                            </div>
                        </div>

                        <!-- Action Buttons -->
                        <div class="d-grid gap-2 mt-4">
                            <button type="submit" name="save_sales" class="btn btn-primary fw-bold">
                                <i class="fas fa-check-circle me-2"></i>Save Calculation
                            </button>
                            <a href="dashboard.php" class="btn btn-light border">Cancel</a>
                        </div>

                    </form>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- JAVASCRIPT FOR AUTO-CALCULATION -->
<script>
document.addEventListener('DOMContentLoaded', function() {
    const serviceSelect = document.getElementById('service_id');
    const inputMonthly = document.getElementById('amt_monthly');
    const inputBi = document.getElementById('amt_bimonthly');
    const inputQuarterly = document.getElementById('amt_quarterly');
    const inputBiannual = document.getElementById('amt_biannually');

    // 1. Function to Auto-Fill Base Amount based on Service Selection
    // (This mimics your previous logic: selecting a service auto-fills the Monthly box)
    function autoFillBaseRate() {
        const selectedOption = serviceSelect.options[serviceSelect.selectedIndex];
        if (!selectedOption || !selectedOption.hasAttribute('data-name')) return;
        
        const serviceName = selectedOption.getAttribute('data-name').toUpperCase();
        let basePrice = 0;

        // Logic from your previous request/Excel
        if (serviceName.includes("CE+TS+XML")) {
            basePrice = 10000;
        } else if (serviceName.includes("CE+TS") || serviceName.includes("80%")) {
            basePrice = 8000; 
        } else if (serviceName.includes("TS") && serviceName.includes("60%")) {
            basePrice = 6000;
        } else if (serviceName.includes("TS")) {
            basePrice = 6000;
        } else if (serviceName.includes("XML") || serviceName.includes("20%")) {
            basePrice = 2000;
        } else if (serviceName.includes("CE") || serviceName.includes("COPYEDITING")) {
            basePrice = 2000;
        } else if (serviceName.includes("BOOK COUNT")) {
            basePrice = 1000;
        } else if (serviceName.includes("CONFERENCE")) {
            basePrice = 500;
        }

        // Set the value and trigger calculation
        if(basePrice > 0) {
            inputMonthly.value = basePrice;
            calculateBreakdown(); // Run the math immediately
        } else {
            inputMonthly.value = ""; // Clear if unknown, let user type
            calculateBreakdown();
        }
    }

    // 2. Function to Calculate the 3 other boxes based on Monthly Input
    function calculateBreakdown() {
        let monthly = parseFloat(inputMonthly.value);

        if (isNaN(monthly) || monthly <= 0) {
            inputBi.value = "";
            inputQuarterly.value = "";
            inputBiannual.value = "";
            return;
        }

        // Logic based on your Excel:
        // Bi-monthly = Monthly / 2
        // Quarterly = Monthly / 4
        // Bi-annually = Monthly / 6
        
        inputBi.value = Math.round(monthly / 2);
        inputQuarterly.value = Math.round(monthly / 4);
        inputBiannual.value = Math.round(monthly / 6);
    }

    // Event Listeners
    // When service changes -> Try to find default price -> then calculate
    serviceSelect.addEventListener('change', autoFillBaseRate);

    // When user manually types in Monthly box -> Recalculate everything
    inputMonthly.addEventListener('input', calculateBreakdown);
});
</script>

<!-- <?php // require 'footer.php'; ?> -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>