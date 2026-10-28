<?php
require 'db.php';
if (session_status() === PHP_SESSION_NONE) { session_start(); }

// 1. HANDLE FORM SUBMISSIONS
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Add New Category
    if (isset($_POST['add_category'])) {
        $name = $mysqli->real_escape_string($_POST['category_name']);
        if(!empty($name)){
            $mysqli->query("INSERT INTO math_category_master (category_name) VALUES ('$name')");
            // Redirect to clear post data
            header("Location: math_category_master.php?msg=added"); exit;
        }
    }
    
    // Update Category
    if (isset($_POST['update_category'])) {
        $id = (int)$_POST['edit_id'];
        $name = $mysqli->real_escape_string($_POST['edit_category_name']);
        
        if(!empty($name)){
            $mysqli->query("UPDATE math_category_master SET category_name = '$name' WHERE id = $id");
            header("Location: math_category_master.php?msg=updated"); exit;
        }
    }
    // Delete Category
    if (isset($_POST['delete_id'])) {
        $id = (int)$_POST['delete_id'];
        $mysqli->query("DELETE FROM math_category_master WHERE id = $id");
        header("Location: math_category_master.php?msg=deleted"); exit;
    }
}

// 2. SEARCH & FETCH LOGIC
$search = "";
$whereSQL = "";
if (isset($_GET['search']) && !empty($_GET['search'])) {
    $search = $mysqli->real_escape_string($_GET['search']);
    $whereSQL = "WHERE category_name LIKE '%$search%'";
}

// Fetch Data
$query = "SELECT * FROM math_category_master $whereSQL ORDER BY id DESC";
$cats = $mysqli->query($query);

require 'header.php';
?>
<style>
    .table thead th {
        background-color: #6c757d !important;
        color: white !important;
        border-bottom: none !important;
    }
</style>

<!-- Main Container with top margin to clear fixed header -->
<div class="container-fluid py-4" style="margin-top: 60px;">
    
    <!-- PAGE HEADER: Title & Add Button -->
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h3 class="fw-bold text-dark"><i class="fas fa-calculator me-2"></i>Math Category Master</h3>
            <p class="text-muted mb-0">Manage all available math categories and definitions</p>
        </div>
        <!-- Button triggers modal -->
        <button class="btn btn-success fw-bold px-4 py-2" data-bs-toggle="modal" data-bs-target="#addCategoryModal">
            <i class="fas fa-plus me-2"></i> Add New Category
        </button>
    </div>

    <!-- SEARCH & FILTER SECTION -->
    <div class="card shadow-sm mb-4 border-0">
        <div class="card-body py-3">
            <div class="row">
                <!-- Changed col-md-12 to col-md-6 to reduce width to 50% -->
                <div class="col-md-6">
                    <!--h6 class="fw-bold text-secondary mb-2"><i class="fas fa-search me-1"></i> Search Categories</h6-->
                    <form method="get" class="d-flex gap-2">
                        <input type="text" name="search" class="form-control" placeholder="Search..." value="<?= htmlspecialchars($search) ?>">
                        
                        <!-- Removed px-4 to reduce button size -->
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-search me-1"></i> Search
                        </button>
                        
                        <?php if(!empty($search)): ?>
                            <a href="math_category_master.php" class="btn btn-secondary">
                                <i class="fas fa-times"></i>
                            </a>
                        <?php endif; ?>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <!-- TABLE SECTION -->
    <!-- Cyan/Info Background Header -->
    <div class="card shadow border-0">
        <div class="card-header bg-info text-white py-3 d-flex justify-content-between align-items-center">
            <h5 class="mb-0 fw-bold"><i class="fas fa-list-ul me-2"></i>All Categories</h5>
            <span class="badge bg-white text-info fw-bold fs-6 px-3"><?= $cats->num_rows ?> records found</span>
        </div>
        
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0 text-center">
                    <thead>
                        <tr>
                            <th class="py-3" width="10%">S.No</th>
                            <th class="py-3 text-start" width="70%">Category Name</th>
                            <th class="py-3" width="20%">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($cats->num_rows > 0): ?>
                            <?php $i = 0; /* 1. Initialize Counter */ ?>
                            <?php while($row = $cats->fetch_assoc()): ?>
                            <?php $i++; /* 2. Increment Counter */ ?>
                            <tr>
                                <!-- 3. Display the Counter variable $i instead of $row['id'] -->
                                <td class="fw-bold text-secondary"><?= $i ?></td>
                                
                                <td class="text-start text-dark"><?= htmlspecialchars($row['category_name']) ?></td>
                                <td>
                                    <button type="button" class="btn btn-primary btn-sm px-3 me-2 edit-btn" 
                                            data-bs-toggle="modal" 
                                            data-bs-target="#editCategoryModal" 
                                            data-id="<?= $row['id'] ?>" 
                                            data-name="<?= htmlspecialchars($row['category_name']) ?>">
                                        <i class="fas fa-edit"></i>
                                    </button>
                                    <form method="post" onsubmit="return confirm('Are you sure you want to delete this category?');" class="d-inline">
                                        <input type="hidden" name="delete_id" value="<?= $row['id'] ?>">
                                        <button type="submit" class="btn btn-danger btn-sm px-3" title="Delete">
                                            <i class="fas fa-trash-alt"></i>
                                        </button>
                                    </form>
                                </td>
                            </tr>
                            <?php endwhile; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="3" class="text-center py-5 text-muted">
                                    <i class="fas fa-folder-open fa-3x mb-3 text-secondary opacity-50"></i><br>
                                    No categories found.
                                </td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<!-- ADD CATEGORY MODAL -->
<div class="modal fade" id="addCategoryModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header bg-success text-white">
                <h5 class="modal-title fw-bold"><i class="fas fa-plus-circle me-2"></i>Add New Category</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body p-4">
                <form method="post">
                    <div class="mb-3">
                        <label class="form-label fw-bold">Category Name</label>
                        <input type="text" name="category_name" class="form-control form-control-lg" placeholder="e.g. In-line Math (Editable)" required>
                        <div class="form-text text-muted">Enter the name exactly as it should appear in reports.</div>
                    </div>
                    <div class="d-grid gap-2">
                        <button type="submit" name="add_category" class="btn btn-success btn-lg fw-bold">
                            <i class="fas fa-save me-2"></i> Save Category
                        </button>
                        <button type="button" class="btn btn-light border" data-bs-dismiss="modal">Cancel</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>
<!-- EDIT CATEGORY MODAL -->
<div class="modal fade" id="editCategoryModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title fw-bold"><i class="fas fa-edit me-2"></i>Edit Category</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body p-4">
                <form method="post">
                    <!-- Hidden ID Field -->
                    <input type="hidden" name="edit_id" id="edit_id">
                    
                    <div class="mb-3">
                        <label class="form-label fw-bold">Category Name</label>
                        <input type="text" name="edit_category_name" id="edit_category_name" class="form-control form-control-lg" required>
                    </div>
                    <div class="d-grid gap-2">
                        <button type="submit" name="update_category" class="btn btn-primary btn-lg fw-bold">
                            <i class="fas fa-save me-2"></i> Update Category
                        </button>
                        <button type="button" class="btn btn-light border" data-bs-dismiss="modal">Cancel</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<!-- <?php // require 'footer.php'; ?> -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
    // Script to pass data from the table button to the modal
    document.addEventListener('DOMContentLoaded', function () {
        var editButtons = document.querySelectorAll('.edit-btn');
        
        editButtons.forEach(function (button) {
            button.addEventListener('click', function () {
                var id = this.getAttribute('data-id');
                var name = this.getAttribute('data-name');
                
                document.getElementById('edit_id').value = id;
                document.getElementById('edit_category_name').value = name;
            });
        });
    });
</script>
</body>
</html>