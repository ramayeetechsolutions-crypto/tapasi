 <?php require 'header.php';?>
  <div class="main-content">
        <div class="job-master-container">
 <?php
require 'db.php';

$msg = "";
if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $old = $_POST["old_password"];
    $new = $_POST["new_password"];

    // Fetch current password hash
    $stmt = $mysqli->prepare("SELECT password_hash FROM users WHERE id=?");
    $stmt->bind_param("i", $_SESSION["uid"]);
    $stmt->execute();
    $hash = ($stmt->get_result()->fetch_assoc())["password_hash"];

    // Verify old password
    if (password_verify($old, $hash)) {
        $newHash = password_hash($new, PASSWORD_BCRYPT);
        $upd = $mysqli->prepare("UPDATE users SET password_hash=? WHERE id=?");
        $upd->bind_param("si", $newHash, $_SESSION["uid"]);
        $upd->execute();
        $msg = "<div class='alert alert-success'>Password changed successfully.</div>";
    } else {
        $msg = "<div class='alert alert-danger'>Old password incorrect.</div>";
    }
}
?>
<style>
body { background:#f4f6f9; font-family:'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; }
.container { max-width:500px; margin-top:40px; }

/* Card styling */
.card { border:none; border-radius:12px; box-shadow:0 2px 6px rgba(0,0,0,0.08); padding:20px; }

/* Password wrapper for eye toggle */
.password-wrapper { position: relative; }
.toggle-password { position: absolute; top:50%; right:12px; transform:translateY(-50%); cursor:pointer; font-size:20px; color:#000; }
</style>


<div class="container">
  <div class="card">
    <h4 class="mb-3">Change Password</h4>
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
        <button type="submit" class="btn btn-primary">Update</button>
        <a href="dashboard.php" class="btn btn-secondary">Back</a>
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
        icon.classList.replace("fa-eye-slash","fa-eye");
    } else {
        pw.type = "password";
        icon.classList.replace("fa-eye","fa-eye-slash");
    }
}
</script>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
