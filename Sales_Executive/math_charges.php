<?php
require 'db.php';
if (session_status() === PHP_SESSION_NONE) { session_start(); }

// ==========================================
// 1. HANDLE FORM SUBMISSIONS (CRUD)
// ==========================================

// --- DELETE LOGIC ---
if (isset($_POST['delete_rate_id'])) {
    $del_id = (int)$_POST['delete_rate_id'];
    if($mysqli->query("DELETE FROM math_rate_card WHERE id = $del_id")) {
        $msg = "Rate deleted successfully!";
    } else {
        $error = "Error deleting rate: " . $mysqli->error;
    }
}

// --- SAVE / UPDATE LOGIC ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    
    // Check if we are Saving (New) or Updating (Edit)
    if (isset($_POST['save_rate']) || isset($_POST['update_rate'])) {
        
        // Sanitize Inputs
        $service    = $mysqli->real_escape_string($_POST['service_type']);
        
        // Ensure currency is exactly 3 chars (e.g. "USD") to match DB limit
        $currency   = substr($mysqli->real_escape_string($_POST['currency']), 0, 3);
        
        $cat_id     = (int)$_POST['category_id'];
        $complexity = $mysqli->real_escape_string($_POST['complexity']);
        $from       = (int)$_POST['eqn_from'];
        $to         = (int)$_POST['eqn_to'];
        $year       = (int)$_POST['rate_year'];
        $date       = $_POST['effective_date'];
        $price      = (float)$_POST['price'];

        if (isset($_POST['update_rate'])) {
            // === UPDATE EXISTING RECORD ===
            $edit_id = (int)$_POST['edit_id'];
            
            $sql = "UPDATE math_rate_card SET 
                    service_type='$service', 
                    currency='$currency', 
                    category_id='$cat_id', 
                    complexity='$complexity', 
                    eqn_from='$from', 
                    eqn_to='$to', 
                    rate_year='$year', 
                    effective_date='$date', 
                    price='$price' 
                    WHERE id='$edit_id'";
            
            $success_msg = "Rate Updated Successfully!";
        } else {
            // === INSERT NEW RECORD ===
            $sql = "INSERT INTO math_rate_card 
                    (service_type, currency, category_id, complexity, eqn_from, eqn_to, rate_year, effective_date, price) 
                    VALUES 
                    ('$service', '$currency', '$cat_id', '$complexity', '$from', '$to', '$year', '$date', '$price')";
            
            $success_msg = "Rate Saved Successfully!";
        }

        // Execute Query
        if($mysqli->query($sql)) {
            // Success: Redirect to clear POST data
            // We use 'math_charges' (no extension) assuming your server uses clean URLs, 
            // or we can use $_SERVER['PHP_SELF'] to be safe.
            header("Location: math_charges.php?msg=" . urlencode($success_msg));
            exit;
        } else {
            // Error: Show exact SQL error on screen
            $error = "Database Error: " . $mysqli->error;
        }
    }
}

// ==========================================
// 2. FETCH DATA FOR EDITING
// ==========================================
$editData = null;
if (isset($_GET['edit_id'])) {
    $id = (int)$_GET['edit_id'];
    $result = $mysqli->query("SELECT * FROM math_rate_card WHERE id = $id");
    if($result->num_rows > 0){
        $editData = $result->fetch_assoc();
    }
}

// ==========================================
// 3. FETCH DROPDOWNS & TABLE LIST
// ==========================================
$categories = $mysqli->query("SELECT * FROM math_category_master WHERE status='Active'");
$rates = $mysqli->query("SELECT r.*, c.category_name 
                         FROM math_rate_card r 
                         LEFT JOIN math_category_master c ON r.category_id = c.id 
                         ORDER BY r.id DESC");

require 'header.php';
?>
<style>
    .table thead th {
        background-color: #6c757d !important;
        color: white !important;
        border-bottom: none !important;
    }
</style>

<!-- Style to clear fixed header if you have one -->
<div class="container-fluid py-4" style="margin-top: 60px;">
    <h3>Math Charges</h3>
    
    <!-- Messages -->
    <?php if(isset($_GET['msg'])) echo "<div class='alert alert-success'>".htmlspecialchars($_GET['msg'])."</div>"; ?>
    <?php if(isset($msg)) echo "<div class='alert alert-success'>$msg</div>"; ?>
    <?php if(isset($error)) echo "<div class='alert alert-danger'>$error</div>"; ?>

    <div class="row h-100">
        
        <!-- ================= LEFT: FORM (ADD / EDIT) ================= -->
        <div class="col-md-3">
            <div class="card shadow-sm h-100">
                <div class="card-header <?= $editData ? 'bg-warning text-dark' : 'bg-secondary text-white' ?> py-3">
                    <h6 class="mb-0">
                        <i class="fas <?= $editData ? 'fa-edit' : 'fa-plus-circle' ?> me-2"></i>
                        <?= $editData ? 'Update Math Charge' : 'Add Math Charge' ?>
                    </h6>
                </div>
                <div class="card-body p-4">
                    
                    <!-- ★★★ CRITICAL FIX: Action is empty to prevent redirect stripping POST data ★★★ -->
                    <form method="post" action="">
                        
                        <!-- Hidden ID for Edit -->
                        <?php if($editData): ?>
                            <input type="hidden" name="edit_id" value="<?= $editData['id'] ?>">
                        <?php endif; ?>

                        <!-- Service Process -->
                        <div class="mb-3">
                            <label class="form-label fw-bold">Service Process</label>
                            <select name="service_type" class="form-select" required>
                                <option value="">Select Service Process</option>
                                <option value="CE & TS" <?= ($editData && $editData['service_type'] == 'CE & TS') ? 'selected' : '' ?>>CE & TS</option>
                                <option value="TS" <?= ($editData && $editData['service_type'] == 'TS') ? 'selected' : '' ?>>TS</option>
                            </select>
                        </div>

                        <!-- Currency -->
                        <div class="mb-3">
                            <label class="form-label fw-bold">Currency</label>
                            <select name="currency" id="currency_select" class="form-select" required>
                                <option value="">Select Currency</option>
                                <option value="USD" <?= ($editData && $editData['currency'] == 'USD') ? 'selected' : '' ?>>USD / Euro</option>
                                <option value="INR" <?= ($editData && $editData['currency'] == 'INR') ? 'selected' : '' ?>>INR</option>
                            </select>
                        </div>

                        <!-- Category -->
                        <div class="mb-3">
                            <label class="form-label fw-bold">Math Category</label>
                            <select name="category_id" id="category" class="form-select" required>
                                <option value="" disabled <?= !$editData ? 'selected' : '' ?>>Select Category</option>
                                <?php 
                                if($categories->num_rows > 0) {
                                    $categories->data_seek(0); 
                                    while($cat = $categories->fetch_assoc()): 
                                ?>
                                    <option value="<?= $cat['id'] ?>" 
                                        data-name="<?= htmlspecialchars($cat['category_name']) ?>"
                                        <?= ($editData && $editData['category_id'] == $cat['id']) ? 'selected' : '' ?>
                                    >
                                        <?= htmlspecialchars($cat['category_name']) ?>
                                    </option>
                                <?php 
                                    endwhile; 
                                }
                                ?>
                            </select>
                        </div>

                        <!-- Complexity -->
                        <div class="mb-3">
                            <label class="form-label fw-bold">Complexity</label>
                            <select name="complexity" id="complexity" class="form-select" required>
                                <option value="" disabled <?= !$editData ? 'selected' : '' ?>>Select Complexity</option>
                                <option value="Simple" <?= ($editData && $editData['complexity'] == 'Simple') ? 'selected' : '' ?>>Simple</option>
                                <option value="Medium" <?= ($editData && $editData['complexity'] == 'Medium') ? 'selected' : '' ?>>Medium</option>
                                <option value="Complex" <?= ($editData && $editData['complexity'] == 'Complex') ? 'selected' : '' ?>>Complex</option>
                            </select>
                        </div>

                        <!-- Equation Range -->
                        <div class="mb-3">
                            <label class="form-label fw-bold">No. of Equations (Range)</label>
                            <div class="input-group">
                                <span class="input-group-text">From</span>
                                <input type="number" name="eqn_from" id="eqn_from" class="form-control" placeholder="0" 
                                       value="<?= $editData ? $editData['eqn_from'] : '' ?>" required>
                            </div>
                            <div class="input-group mt-2">
                                <span class="input-group-text px-3">To</span>
                                <input type="number" name="eqn_to" id="eqn_to" class="form-control" placeholder="10" 
                                       value="<?= $editData ? $editData['eqn_to'] : '' ?>" required>
                            </div>
                        </div>
                        
                        <!-- Year -->
                        <div class="mb-3">
                            <label class="form-label fw-bold">Rate Year</label>
                            <input type="number" name="rate_year" class="form-control" 
                                   value="<?= $editData ? $editData['rate_year'] : '2025' ?>" required>
                        </div>

                        <!-- Date -->
                        <div class="mb-3">
                            <label class="form-label fw-bold">Effective Date</label>
                            <input type="date" name="effective_date" class="form-control" 
                                   value="<?= $editData ? $editData['effective_date'] : date('Y-m-d') ?>" required>
                        </div>

                        <!-- Price -->
                        <div class="mb-4">
                            <label class="form-label fw-bold text-success fs-5">Price / Rate</label>
                            <div class="input-group input-group-lg">
                                <span class="input-group-text" id="price_symbol">
                                    <?= ($editData && $editData['currency'] == 'INR') ? '₹' : '$' ?>
                                </span>
                                <input type="number" step="0.01" name="price" class="form-control" placeholder="0.00" 
                                       value="<?= $editData ? $editData['price'] : '' ?>" required>
                            </div>
                        </div>

                        <!-- Dynamic Buttons -->
                        <?php if($editData): ?>
                            <button type="submit" name="update_rate" class="btn btn-warning w-100 py-2 fw-bold mb-2">
                                <i class="fas fa-sync-alt me-2"></i> Update Rate
                            </button>
                            <a href="math_charges.php" class="btn btn-secondary w-100 py-2 fw-bold">
                                <i class="fas fa-times me-2"></i> Cancel Edit
                            </a>
                        <?php else: ?>
                            <button type="submit" name="save_rate" class="btn btn-primary w-100 py-2 fw-bold">
                                <i class="fas fa-save me-2"></i> Save Rate
                            </button>
                        <?php endif; ?>

                    </form>
                </div>
            </div>
        </div>

        <!-- ================= RIGHT: DATA TABLE ================= -->
        <div class="col-md-9">
            <div class="card shadow-sm" style="height: calc(100vh - 150px); overflow-y: auto;"> 
                <div class="card-header bg-light py-3 d-flex justify-content-between align-items-center">
                    <h6 class="mb-0">Current Rate Card Database</h6>
                    <span class="badge bg-secondary"><?= $rates->num_rows ?> Records found</span>
                </div>
                <div class="table-responsive">
                    <table class="table table-hover mb-0 text-center align-middle">
                        <thead>
                            <tr>
                                <th class="py-3">Service</th>
                                <th class="py-3">Category</th>
                                <th class="py-3">Complexity</th>
                                <th class="py-3">Range (Eqn)</th>
                                <th class="py-3">Year</th>
                                <th class="py-3">Price</th>
                                <th class="py-3" width="120">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($rates->num_rows > 0): ?>
                                <?php while($r = $rates->fetch_assoc()): ?>
                                <tr class="<?= ($editData && $editData['id'] == $r['id']) ? 'table-warning' : '' ?>">
                                    <td class="py-3">
                                        <div class="fw-bold text-dark"><?= htmlspecialchars($r['service_type']) ?></div>
                                        <small class="text-muted"><?= htmlspecialchars($r['currency']) ?></small>
                                    </td>
                                    <td class="text-start fw-bold text-primary"><?= htmlspecialchars($r['category_name']) ?></td>
                                    <td>
                                        <?php 
                                            $badge = 'bg-success';
                                            if($r['complexity'] == 'Medium') $badge = 'bg-warning text-dark';
                                            if($r['complexity'] == 'Complex') $badge = 'bg-danger';
                                        ?>
                                        <span class="badge <?= $badge ?>"><?= htmlspecialchars($r['complexity']) ?></span>
                                    </td>
                                    <td class="fw-bold"><?= $r['eqn_from'] ?> - <?= $r['eqn_to'] ?></td>
                                    <td><?= $r['rate_year'] ?></td>
                                    <td class="fw-bold text-success fs-5">
                                        <?= ($r['currency'] == 'INR' ? '₹' : '$') ?> <?= number_format($r['price'], 2) ?>
                                    </td>
                                    
                                    <!-- ACTION BUTTONS -->
                                    <td>
                                        <div class="d-flex justify-content-center gap-2">
                                            <!-- Edit Link -->
                                            <a href="math_charges.php?edit_id=<?= $r['id'] ?>" class="btn btn-sm btn-outline-primary" title="Edit">
                                                <i class="fas fa-edit"></i>
                                            </a>

                                            <!-- Delete Form -->
                                            <form method="post" onsubmit="return confirm('Are you sure you want to delete this rate?');" style="display:inline;">
                                                <input type="hidden" name="delete_rate_id" value="<?= $r['id'] ?>">
                                                <button type="submit" class="btn btn-sm btn-outline-danger" title="Delete">
                                                    <i class="fas fa-trash-alt"></i>
                                                </button>
                                            </form>
                                        </div>
                                    </td>
                                </tr>
                                <?php endwhile; ?>
                            <?php else: ?>
                                <tr><td colspan="7" class="text-muted py-5 fs-5">No rates defined yet.</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>

<script>
document.addEventListener('DOMContentLoaded', function() {
    
    const alerts = document.querySelectorAll('.alert');
    if (alerts.length > 0) {
        setTimeout(function() {
            alerts.forEach(function(alert) {
                alert.style.transition = "opacity 0.5s ease";
                alert.style.opacity = "0";
                setTimeout(function() { alert.remove(); }, 500); 
            });
        }, 2000); // <--- 2000 means 2 seconds. Change to 1000 for 1 second.
    }
    // --- VARIABLES ---
    const catSelect = document.getElementById('category');
    const compSelect = document.getElementById('complexity');
    const inputFrom = document.getElementById('eqn_from');
    const inputTo = document.getElementById('eqn_to');
    const curSelect = document.getElementById('currency_select');
    const curSymbol = document.getElementById('price_symbol');

    // --- 1. CURRENCY SYMBOL LOGIC ---
    function updateCurrencySymbol() {
        if (curSelect && curSelect.value === 'INR') {
            curSymbol.textContent = '₹'; // Set Rupee
        } else if (curSymbol) {
            curSymbol.textContent = '$'; // Set Dollar
        }
    }

    if(curSelect && curSymbol) {
        // Run immediately on page load (Handles Edit Mode & Default)
        updateCurrencySymbol();
        // Run whenever the user changes the dropdown
        curSelect.addEventListener('change', updateCurrencySymbol);
    }

    // --- 2. RANGE UPDATE LOGIC ---
    function updateRange() {
        // Stop if we are in Edit Mode (inputs already have values)
        // You can check if the URL has ?edit_id or check if inputs are empty
        const isEditMode = "<?= $editData ? 'true' : 'false' ?>" === "true";
        if (isEditMode) return;

        const selectedOption = catSelect.options[catSelect.selectedIndex];
        const catName = selectedOption ? (selectedOption.getAttribute('data-name') || '') : ''; 
        const complexity = compSelect.value;

        // Only auto-fill if the fields are empty or user is actively selecting
        if(catName === '' || complexity === '') return;

        let from = '';
        let to = '';

        if (catName.includes('In-line') || catName.includes('Inline')) {
            if (complexity === 'Simple') { from = 0; to = 10; } 
            else if (complexity === 'Medium') { from = 11; to = 30; } 
            else if (complexity === 'Complex') { from = 31; to = 100; }
        }
        else if (catName.includes('Display Math')) {
            if (complexity === 'Simple') { from = 0; to = 20; } 
            else if (complexity === 'Medium') { from = 21; to = 50; } 
            else if (complexity === 'Complex') { from = 51; to = 100; }
        }
        else if (catName.includes('Non-Editable') || catName.includes('Non Editable')) {
            if (complexity === 'Simple') { from = 0; to = 5; } 
            else if (complexity === 'Medium') { from = 6; to = 20; } 
            else if (complexity === 'Complex') { from = 21; to = 100; }
        }
        
        // Only update if logic found a range
        if(from !== '' && to !== '') {
            inputFrom.value = from;
            inputTo.value = to;
        }
    }

    // Add listeners for Range Logic
    if(catSelect && compSelect) {
        catSelect.addEventListener('change', updateRange);
        compSelect.addEventListener('change', updateRange);
    }
});
</script>
</body>
</html>