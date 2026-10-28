<?php
require_once 'db.php';

$message = '';

// Handle form actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['create_user'])) {
        $username = trim($_POST['username']);
        $role_id = (int)$_POST['role_id'];
        $password = $_POST['password'];
        
        // Check if username exists
        $check_query = "SELECT id FROM users WHERE username = ?";
        $check_stmt = $mysqli->prepare($check_query);
        $check_stmt->bind_param("s", $username);
        $check_stmt->execute();
        $check_result = $check_stmt->get_result();
        
        if ($check_result->num_rows > 0) {
            $message = '<div class="alert alert-danger">Username already exists!</div>';
        } else {
            // Create new user
            $password_hash = password_hash($password, PASSWORD_DEFAULT);
            $query = "INSERT INTO users (username, role_id, password_hash, created_at) 
                     VALUES (?, ?, ?, NOW())";
            
            $stmt = $mysqli->prepare($query);
            $stmt->bind_param("sis", $username, $role_id, $password_hash);
            
            if ($stmt->execute()) {
                $message = '<div class="alert alert-success">User created successfully!</div>';
            } else {
                $message = '<div class="alert alert-danger">Failed to create user!</div>';
            }
        }
    }
    
    elseif (isset($_POST['update_user'])) {
        $user_id = (int)$_POST['user_id'];
        $username = trim($_POST['username']);
        $role_id = (int)$_POST['role_id'];
        $password = $_POST['password'];
        
        if (!empty($password)) {
            // Update with new password
            $password_hash = password_hash($password, PASSWORD_DEFAULT);
            $query = "UPDATE users SET username = ?, role_id = ?, password_hash = ? WHERE id = ?";
            $stmt = $mysqli->prepare($query);
            $stmt->bind_param("sisi", $username, $role_id, $password_hash, $user_id);
        } else {
            // Update without changing password
            $query = "UPDATE users SET username = ?, role_id = ? WHERE id = ?";
            $stmt = $mysqli->prepare($query);
            $stmt->bind_param("sii", $username, $role_id, $user_id);
        }
        
        if ($stmt->execute()) {
            $message = '<div class="alert alert-success">User updated successfully!</div>';
        } else {
            $message = '<div class="alert alert-danger">Failed to update user!</div>';
        }
    }
    
    elseif (isset($_POST['delete_user'])) {
        $user_id = (int)$_POST['user_id'];
        
        $query = "DELETE FROM users WHERE id = ?";
        $stmt = $mysqli->prepare($query);
        $stmt->bind_param("i", $user_id);
        
        if ($stmt->execute()) {
            $message = '<div class="alert alert-success">User deleted successfully!</div>';
        } else {
            $message = '<div class="alert alert-danger">Failed to delete user!</div>';
        }
    }
}

// Get active roles for dropdown
$roles_query = "SELECT role_id, role_name, role_description 
               FROM user_role_master 
               WHERE status = 'active' 
               ORDER BY role_name";
$roles_result = $mysqli->query($roles_query);

// Get all users with role details for display
$users_query = "SELECT u.id, u.username, u.role_id, u.created_at, 
                       r.role_name, r.role_description, r.status as role_status
                FROM users u
                LEFT JOIN user_role_master r ON u.role_id = r.role_id
                ORDER BY u.created_at DESC";
$users_result = $mysqli->query($users_query);
require 'header.php';
?>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <style>
        /* .user-table th { background-color: #f8f9fa; }
        .action-btns { white-space: nowrap; }
        .role-badge { font-size: 0.8em; } */
        .card-header.bg-primary, .badge.bg-primary {
            background-color: #707981 !important;
            border-color: #707981 !important;
        }
    </style>
    <br><br><br>
    <div class="container mt-4">
        <div class="row">
            <div class="col-12">
                <h2 class="mb-4"><i class="fas fa-users"></i> User Management</h2>
                
                <?php echo $message; ?>
                
                <!-- Create User Form -->
                <div class="card mb-4">
                    <div class="card-header bg-primary text-white">
                        <h5 class="mb-0"><i class="fas fa-plus-circle"></i> Create New User</h5>
                    </div>
                    <div class="card-body">
                        <form method="POST">
                            <div class="row">
                                <div class="col-md-3">
                                    <div class="mb-3">
                                        <label for="username" class="form-label">Username *</label>
                                        <input type="text" class="form-control" id="username" name="username" required>
                                    </div>
                                </div>
                                <div class="col-md-3">
                                   <div class="mb-3">
    <label for="role_id" class="form-label">Role *</label>
    <select class="form-select" id="role_id" name="role_id" required>
        <option value="">Select Role</option>
        <?php if ($roles_result && $roles_result->num_rows > 0): ?>
            <?php while ($role = $roles_result->fetch_assoc()): ?>
                <?php 
                // Skip TL and TM roles
                $role_name = $role['role_name'];
                if ($role_name === 'TL' || $role_name === 'TM') {
                    continue;
                }
                ?>
                <option value="<?php echo $role['role_id']; ?>">
                    <?php echo htmlspecialchars($role['role_name']); ?>
                    <?php if (!empty($role['role_description'])): ?>
                        - <?php echo htmlspecialchars($role['role_description']); ?>
                    <?php endif; ?>
                </option>
            <?php endwhile; ?>
        <?php else: ?>
            <option value="">No roles available</option>
        <?php endif; ?>
    </select>
</div>                                </div>
                                <div class="col-md-3">
                                    <div class="mb-3">
                                        <label for="password" class="form-label">Password *</label>
                                        <input type="password" class="form-control" id="password" name="password" required>
                                    </div>
                                </div>
                                <div class="col-md-3">
                                    <br>
                                    <div class="mb-3 d-flex align-items-end">
                                        <button type="submit" name="create_user" class="btn btn-success w-100">
                                            <i class="fas fa-save"></i> Create User
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </form>
                    </div>
                </div>

                <!-- Users List -->
                <div class="card">
                    <div class="card-header bg-secondary text-white">
                        <h5 class="mb-0"><i class="fas fa-list"></i> All Users</h5>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-striped table-hover user-table">
                                <thead>
                                    <tr>
                                        <th>S.No</th>
                                        <th>Username</th>
                                        <th>Role</th>
                                        <th>Created At</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if ($users_result && $users_result->num_rows > 0): ?>
                                        <?php $i=0; while ($user_row = $users_result->fetch_assoc()): $i=$i+1;?>
                                            <tr>
                                                <td><?php echo $i; ?></td>
                                                <td><?php echo htmlspecialchars($user_row['username']); ?></td>
                                                <td>
                                                    <span class="badge bg-primary role-badge">
                                                        <?php echo htmlspecialchars($user_row['role_name'] ?? 'N/A'); ?>
                                                    </span>
                                                    <?php if (!empty($user_row['role_description'])): ?>
                                                        <br><small class="text-muted"><?php echo htmlspecialchars($user_row['role_description']); ?></small>
                                                    <?php endif; ?>
                                                </td>
                                                <td><?php echo date('M j, Y g:i A', strtotime($user_row['created_at'])); ?></td>
                                                <td class="action-btns">
                                                    <!-- Edit Button -->
                                                    <button type="button" class="btn btn-warning btn-sm" 
                                                            data-bs-toggle="modal" 
                                                            data-bs-target="#editUserModal"
                                                            data-userid="<?php echo $user_row['id']; ?>"
                                                            data-username="<?php echo htmlspecialchars($user_row['username']); ?>"
                                                            data-roleid="<?php echo $user_row['role_id']; ?>">
                                                        <i class="fas fa-edit"></i> Edit
                                                    </button>
                                                    
                                                    <!-- Delete Button -->
                                                    <form method="POST" style="display: inline;" 
                                                          onsubmit="return confirm('Are you sure you want to delete this user?');">
                                                        <input type="hidden" name="user_id" value="<?php echo $user_row['id']; ?>">
                                                        <button type="submit" name="delete_user" class="btn btn-danger btn-sm">
                                                            <i class="fas fa-trash"></i> Delete
                                                        </button>
                                                    </form>
                                                </td>
                                            </tr>
                                        <?php endwhile; ?>
                                    <?php else: ?>
                                        <tr>
                                            <td colspan="5" class="text-center">No users found.</td>
                                        </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Edit User Modal -->
    <div class="modal fade" id="editUserModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Edit User</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST">
                    <div class="modal-body">
                        <input type="hidden" name="user_id" id="edit_user_id">
                        <div class="mb-3">
                            <label for="edit_username" class="form-label">Username</label>
                            <input type="text" class="form-control" id="edit_username" name="username" required>
                        </div>
                        <div class="mb-3">
                            <label for="edit_role_id" class="form-label">Role</label>
                            <select class="form-select" id="edit_role_id" name="role_id" required>
                                <option value="">Select Role</option>
                                <?php 
                                // Re-fetch roles for the modal
                                $roles_modal = $mysqli->query("SELECT role_id, role_name FROM user_role_master WHERE status = 'active' ORDER BY role_name");
                                if ($roles_modal && $roles_modal->num_rows > 0): 
                                ?>
                                    <?php while ($role = $roles_modal->fetch_assoc()): ?>
                                        <option value="<?php echo $role['role_id']; ?>">
                                            <?php echo htmlspecialchars($role['role_name']); ?>
                                        </option>
                                    <?php endwhile; ?>
                                <?php else: ?>
                                    <option value="">No roles available</option>
                                <?php endif; ?>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label for="edit_password" class="form-label">New Password (leave blank to keep current)</label>
                            <input type="password" class="form-control" id="edit_password" name="password">
                            <div class="form-text">Leave empty if you don't want to change the password.</div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" name="update_user" class="btn btn-primary">Update User</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Populate edit modal with user data
        document.addEventListener('DOMContentLoaded', function() {
            var editUserModal = document.getElementById('editUserModal');
            editUserModal.addEventListener('show.bs.modal', function (event) {
                var button = event.relatedTarget;
                var userId = button.getAttribute('data-userid');
                var username = button.getAttribute('data-username');
                var roleId = button.getAttribute('data-roleid');
                
                document.getElementById('edit_user_id').value = userId;
                document.getElementById('edit_username').value = username;
                document.getElementById('edit_role_id').value = roleId;
            });
        });
    </script>
</body>
</html>