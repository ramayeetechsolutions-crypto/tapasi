<?php 
require 'db.php';
require 'header.php';

$msg = "";
$username = $_SESSION["username"] ?? '';

if (($_SERVER["REQUEST_METHOD"] ?? '') === "POST") {
    $old = trim($_POST["old_password"] ?? '');
    $new = trim($_POST["new_password"] ?? '');

    if ($old === '' || $new === '') {
        $msg = "<div class='alert alert-danger'>Please fill all fields.</div>";
    } elseif (strlen($new) < 4) {
        $msg = "<div class='alert alert-danger'>New password must be at least 4 characters long.</div>";
    } else {
        $old_matched = false;
        
        // 1. Check in employee_master (stored as MD5)
        $stmt_emp = $mysqli->prepare("SELECT employee_id, password_hash FROM employee_master WHERE employee_username = ? LIMIT 1");
        $stmt_emp->bind_param("s", $username);
        $stmt_emp->execute();
        $emp_row = $stmt_emp->get_result()->fetch_assoc();
        $stmt_emp->close();

        if ($emp_row) {
            $emp_hash = $emp_row['password_hash'];
            if (md5($old) === $emp_hash || password_verify($old, $emp_hash)) {
                $old_matched = true;
            }
        }

        // 2. Check in users table (stored as bcrypt or MD5)
        $stmt_user = $mysqli->prepare("SELECT id, password_hash FROM users WHERE username = ? LIMIT 1");
        $stmt_user->bind_param("s", $username);
        $stmt_user->execute();
        $user_row = $stmt_user->get_result()->fetch_assoc();
        $stmt_user->close();

        if ($user_row && !$old_matched) {
            $u_hash = $user_row['password_hash'];
            if (password_verify($old, $u_hash) || md5($old) === $u_hash) {
                $old_matched = true;
            }
        }

        if ($old_matched) {
            $new_md5 = md5($new);
            $new_bcrypt = password_hash($new, PASSWORD_BCRYPT);

            // Update in employee_master
            if ($emp_row) {
                $upd_emp = $mysqli->prepare("UPDATE employee_master SET password_hash = ? WHERE employee_username = ?");
                $upd_emp->bind_param("ss", $new_md5, $username);
                $upd_emp->execute();
                $upd_emp->close();
            }

            // Update in users table if user exists there
            if ($user_row) {
                $upd_user = $mysqli->prepare("UPDATE users SET password_hash = ? WHERE username = ?");
                $upd_user->bind_param("ss", $new_bcrypt, $username);
                $upd_user->execute();
                $upd_user->close();
            }

            $msg = "<div class='alert alert-success'>Password changed successfully.</div>";
        } else {
            $msg = "<div class='alert alert-danger'>Old password incorrect.</div>";
        }
    }
}
?>

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
}

.page-title-container h4 {
    margin: 0;
    font-size: 1.25rem;
    font-weight: 600;
    color: white;
}

body { 
    background: #f4f6f9; 
    font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; 
}

.container-password { 
    max-width: 500px; 
    margin: 40px auto; 
}

.card { 
    border: none; 
    border-radius: 12px; 
    box-shadow: 0 2px 6px rgba(0,0,0,0.08); 
    padding: 20px; 
    background: #fff;
}

.password-wrapper { 
    position: relative; 
}

.toggle-password { 
    position: absolute; 
    top: 50%; 
    right: 12px; 
    transform: translateY(-50%); 
    cursor: pointer; 
    font-size: 18px; 
    color: #6c757d; 
}
</style>

<div class="container-password">
  <div class="card">
    <div class="page-title-container">
        <h4 class="mb-0 text-white"><i class="fas fa-key me-2"></i>Change Password</h4>
    </div>
    <?= $msg ?>
    <form method="post">
      <!-- Old Password -->
      <div class="mb-3 password-wrapper">
        <input type="password" name="old_password" id="old_password" class="form-control" placeholder="Old Password" required>
        <i class="fa-solid fa-eye-slash toggle-password" id="toggleOld" onclick="togglePassword('old_password','toggleOld')"></i>
      </div>

      <!-- New Password -->
      <div class="mb-3 password-wrapper">
        <input type="password" name="new_password" id="new_password" class="form-control" placeholder="New Password" required>
        <i class="fa-solid fa-eye-slash toggle-password" id="toggleNew" onclick="togglePassword('new_password','toggleNew')"></i>
      </div>

      <div class="d-flex justify-content-between">
        <button type="submit" class="btn btn-primary px-4">Update</button>
        <a href="dashboard_tl.php" class="btn btn-secondary px-4">Back</a>
      </div>
    </form>
  </div>
</div>

<script>
function togglePassword(fieldId, iconId) {
    const pw = document.getElementById(fieldId);
    const icon = document.getElementById(iconId);
    if (pw.type === "password") {
        pw.type = "text";
        icon.classList.remove("fa-eye-slash");
        icon.classList.add("fa-eye");
    } else {
        pw.type = "password";
        icon.classList.remove("fa-eye");
        icon.classList.add("fa-eye-slash");
    }
}
</script>
