<?php
// add_team_member.php
require 'db.php';
require 'header.php';

/* ADD THIS BLOCK AFTER require 'header.php' */
$mode = isset($_GET['mode']) ? $_GET['mode'] : 'add';
$emp_username = isset($_GET['emp_username']) ? $_GET['emp_username'] : '';
$tl_name = isset($_GET['tl_name']) ? $_GET['tl_name'] : '';

$selected_emp_id = "";
$selected_process = "";

if ($mode == 'edit') {
    // Fetch current assignment details to pre-fill
    $edit_sql = "SELECT employee_id, process FROM employee_master WHERE employee_username = '$emp_username'";
    $edit_res = $mysqli->query($edit_sql);
    if($row = $edit_res->fetch_assoc()){
        $selected_emp_id = $row['employee_id'];
        $selected_process = $row['process']; // This usually stores the process name or ID
    }
}

// 1. Fetch Employees (ID and Name) from employee_master
$emp_query = "SELECT employee_id, employee_username, employee_name FROM employee_master WHERE user_role_id = 4 AND status='Active' ORDER BY employee_username ASC";
$emp_result = $mysqli->query($emp_query);

// 2. Fetch Only XML Processes from process_master
$proc_query = "SELECT process_id, process_name FROM process_master WHERE process_name LIKE '%XML%' ORDER BY process_name ASC";
$proc_result = $mysqli->query($proc_query);
?>

<form action="save_team_member.php" method="POST">
    
    <?php if($mode == 'edit'): ?>
        <input type="hidden" name="employee_id" value="<?php echo $selected_emp_id; ?>">
    <?php endif; ?>

    <!-- Pre-filled Team Leader Name -->
    <div class="mb-4">

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Add Team Member</title>
    <!-- Select2 CSS for Searchable Dropdown -->
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
    <style>
        .main-content { padding-top: 100px !important; }
        .form-card { max-width: 800px; margin: auto; background: white; padding: 40px; border-radius: 12px; box-shadow: 0 10px 30px rgba(0,0,0,0.1); }
        .form-label { font-weight: 600; color: #5a6268; margin-bottom: 8px; }
        .select2-container--default .select2-selection--single { height: 45px; padding-top: 8px; border: 1px solid #ced4da; }
        .btn-grey { background-color: #5e6b77; color: white; transition: 0.3s; }
        .btn-grey:hover { background-color: #4a545e; color: white; }
    </style>
</head>
<body class="bg-light">
    <div class="main-content">
        <div class="form-card">
            <h3 class="mb-4 text-center" style="color: #5e6b77;">
    <i class="fas <?php echo ($mode == 'edit') ? 'fa-user-edit' : 'fa-user-plus'; ?> me-2"></i>
    <?php echo ($mode == 'edit') ? 'Edit Member Assignment' : 'Add Team Member'; ?>
</h3>
            <hr class="mb-4">
            
            <form action="save_team_member.php" method="POST">
                <!-- Pre-filled Team Leader Name -->
                <div class="mb-4">
                    <label class="form-label">Team Leader Name</label>
                    <input type="text" class="form-control bg-light" name="reporting_manager" value="<?php echo $tl_name; ?>" readonly>
                </div>

                <!-- Searchable Employee Selection -->
                <div class="mb-4">
                    <label class="form-label">Select Employee (Search by ID or Name)</label>
                    <select name="employee_id" class="form-select searchable-select" required <?php echo ($mode == 'edit') ? 'disabled' : ''; ?>>
                        <option value="">-- Start typing ID or Name --</option>
                        <?php while($row = $emp_result->fetch_assoc()): ?>
                            <option value="<?php echo $row['employee_id']; ?>" <?php echo ($row['employee_id'] == $selected_emp_id) ? 'selected' : ''; ?>>
                                <?php echo $row['employee_username'] . " - " . $row['employee_name']; ?>
                            </option>
                        <?php endwhile; ?>
                    </select>
                </div>

                <!-- XML Process Selection -->
                <div class="mb-4">
                    <label class="form-label">Assign XML Process</label>
                    <select name="process_id" class="form-select searchable-select" required>
                        <option value="">-- Search XML Processes --</option>
                        <?php while($row = $proc_result->fetch_assoc()): ?>
    <option value="<?php echo $row['process_name']; ?>" <?php echo ($row['process_name'] == $selected_process) ? 'selected' : ''; ?>>
        <?php echo $row['process_name']; ?>
    </option>
<?php endwhile; ?>
                    </select>
                </div>

                <div class="d-flex justify-content-between mt-5">
                    <a href="team_view.php" class="btn btn-secondary px-4"><i class="fas fa-arrow-left me-2"></i>Cancel</a>
                    <button type="submit" class="btn btn-primary px-5 py-2 fw-bold">
    <i class="fas fa-save me-2"></i>
    <?php echo ($mode == 'edit') ? 'Update Assignment' : 'Assign Member'; ?>
</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Scripts -->
    <script src="https://code.jquery.com/jquery-3.7.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
    <script>
        $(document).ready(function() {
            $('.searchable-select').select2({
                width: '100%'
            });
        });
    </script>
</body>
</html>