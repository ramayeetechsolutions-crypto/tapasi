<?php
session_start();

/* Redirect if already logged in */
if (isset($_SESSION['uid']) && isset($_SESSION['role_name'])) {
    switch ($_SESSION['role_name']) {
        case "Super Admin":
            header("Location: Super_Admin/dashboard_md.php");
            break;
        case "HR":
            header("Location: Admin/dashboard_hr.php");
            break;
        case "Team Lead":
            header("Location: Team_Lead/dashboard_tl.php");
            break;
        case "Executive":
            header("Location: Executive/dashboard_exec.php");
            break;
        case "Admin":
            header("Location: SubAdmin/dashboard.php");
            break;
        case "Sub TL":
            header("Location: SubTL/dashboard.php");
            break;
    }
    exit;
}

require 'db.php'; // Your mysqli connection setup must be in this file

$error = "";

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $u = trim($_POST["username"]);
    $p = $_POST["password"];

    if ($u && $p) {
        // 1. Check in users table (your create_admin.php users)
        $stmt1 = $mysqli->prepare("SELECT id, username, password_hash, role_id FROM users WHERE username = ? LIMIT 1");
        $stmt1->bind_param("s", $u);
        $stmt1->execute();
        $res1 = $stmt1->get_result();
        $userRow = $res1->fetch_assoc();
        $stmt1->close();

        if ($userRow && password_verify($p, $userRow['password_hash'])) {
            // Fetch role_name from user_role_master
            $stmt2 = $mysqli->prepare("SELECT role_name FROM user_role_master WHERE role_id = ? LIMIT 1");
            $stmt2->bind_param("i", $userRow['role_id']);
            $stmt2->execute();
            $res2 = $stmt2->get_result();
            $roleRow = $res2->fetch_assoc();
            $stmt2->close();

            $roleName = $roleRow['role_name'] ?? 'User';

            session_regenerate_id(true);
            $_SESSION["uid"] = $userRow['id'];
            $_SESSION["username"] = $userRow['username'];
            $_SESSION["role_name"] = $roleName;

            switch ($roleName) {
             case "Super Admin":
            header("Location: Super_Admin/dashboard_md.php");
            break;
        case "HR":
            header("Location: Admin/dashboard_hr.php");
            break;
        case "Team Lead":
            header("Location: Team_Lead/dashboard_tl.php");
            break;
        case "Executive":
            header("Location: Executive/dashboard_exec.php");
            break;
        case "Admin":
            header("Location: SubAdmin/dashboard.php");
            break;
        case "Sub TL":
            header("Location: SubTL/dashboard.php");
            break;
        default:
                    header("Location: index.php?error=invalid_role");
            }
            exit;
        }

        // 2. Check in employee_master table
        $stmt3 = $mysqli->prepare("SELECT e.employee_id AS id, e.employee_username AS username, e.password_hash, r.role_name 
            FROM employee_master e 
            JOIN user_role_master r ON e.user_role_id = r.role_id 
            WHERE e.employee_username = ? AND e.status = 'Active'
            LIMIT 1");
        $stmt3->bind_param("s", $u);
        $stmt3->execute();
        $res3 = $stmt3->get_result();
        $empRow = $res3->fetch_assoc();
        $stmt3->close();

        if ($empRow && !empty($empRow["password_hash"]) && password_verify($p, $empRow["password_hash"])) {
            session_regenerate_id(true);
            $_SESSION["uid"] = $empRow["id"];
            $_SESSION["username"] = $empRow["username"];
            $_SESSION["role_name"] = $empRow["role_name"];

            switch ($empRow["role_name"]) {
  case "Super Admin":
            header("Location: Super_Admin/dashboard_md.php");
            break;
        case "HR":
            header("Location: Admin/dashboard_hr.php");
            break;
        case "Team Lead":
            header("Location: Team_Lead/dashboard_tl.php");
            break;
        case "Executive":
            header("Location: Executive/dashboard_exec.php");
            break;
        case "Admin":
            header("Location: SubAdmin/dashboard.php");
            break;
        case "Sub TL":
            header("Location: SubTL/dashboard.php");
            break;
                default:
                    header("Location: index.php?error=invalid_role");
            }
            exit;
        }

        $error = "Invalid username or password!";
    } else {
        $error = "Please enter username & password.";
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <title>Login - Tapasi ERP</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet" />
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" rel="stylesheet" />
    <style>
        body {
            background: #f4f6f9;
            display: flex;
            justify-content: center;
            align-items: center;
            height: 100vh;
            font-family: 'Segoe UI', sans-serif;
        }
        .login-card {
            width: 420px;
            padding: 40px 30px;
            border-radius: 15px;
            box-shadow: 0 6px 20px rgba(0,0,0,.15);
            background: #fff;
            text-align: center;
        }
        .login-card img {
            height: 80px;
            margin-bottom: 20px;
        }
        .btn-primary {
            background: #8B6450 !important;
            border-color: #8B6450 !important;
        }
        .btn-primary:hover {
            background: #4E3620 !important;
            border-color: #4E3620 !important;
        }
    </style>
</head>
<body>
    <div class="login-card">
        <img src="tlogo.jpg" alt="Company Logo" />
        <h3 class="mb-4">Welcome Back</h3>

        <?php if ($error): ?>
            <div class="alert alert-danger py-2"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <form method="post" novalidate>
            <div class="mb-3">
                <label for="username" class="form-label">Username</label>
                <input type="text" name="username" id="username" class="form-control form-control-lg" placeholder="Enter Username" required autofocus />
            </div>

            <div class="mb-3">
                <label for="password" class="form-label">Password</label>
                <div class="input-group input-group-lg">
                    <input type="password" name="password" id="password" class="form-control" placeholder="Enter Password" required />
                    <span class="input-group-text" style="cursor:pointer;" onclick="togglePassword()">
                        <i class="fa-solid fa-eye-slash" id="toggleIcon"></i>
                    </span>
                </div>
            </div>

            <button type="submit" class="btn btn-primary w-100 btn-lg">Login</button>
        </form>
    </div>

    <script>
        function togglePassword() {
            const pw = document.getElementById("password");
            const icon = document.getElementById("toggleIcon");
            if (pw.type === "password") {
                pw.type = "text";
                icon.classList.replace("fa-eye-slash", "fa-eye");
            } else {
                pw.type = "password";
                icon.classList.replace("fa-eye", "fa-eye-slash");
            }
        }
    </script>
</body>
</html>
