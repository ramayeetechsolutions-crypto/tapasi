<?php
session_start();

/* Redirect if already logged in */
/*if (isset($_SESSION['uid']) && isset($_SESSION['role_name'])) {
    $team_id = isset($_SESSION['employee_details']['team_id']) ? $_SESSION['employee_details']['team_id'] : null;

    switch ($_SESSION['role_name']) {
        case "Super Admin":
            $_SESSION['supername']="Super Admin";
            header("Location: Super_Admin/dashboard_md.php");
            break;
        case "HR":
            $_SESSION['hrname']="HR Admin";
            header("Location: Admin/dashboard_hr.php");
            break;
        case "TL":
            $_SESSION['teamname']="Team Lead";
            if ($team_id == 4) {
                header("Location: xml/dashboard_tl.php");
            } else {
                header("Location: Team_Lead/dashboard_tl.php");
            }
            break;
        case "TM":
            $_SESSION['membername']="Team Member";
            if ($team_id == 4) {
                header("Location: xml/dashboard_tl.php");
            } else {
                header("Location: Executive/dashboard_exec.php");
            }
            break;
        case "Admin":
            $_SESSION['adminname']="Sub Admin";
            header("Location: SubAdmin/dashboard.php");
            break;
        case "Sub TL":
            $_SESSION['subname']="Sub TL";
            header("Location: SubTL/dashboard.php");
            break;
        case "SM":
            $_SESSION['salesname']="Sales Market";
            header("Location: Sales/dashboard.php");
            break;
        case "IT":
            $_SESSION['infoname']="Info Tech";
            header("Location: system/dashboard.php");
            break;
    }
    exit;
}
*/
require 'db.php';

$error = "";

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $u = trim($_POST["username"]);
    $p = $_POST["password"];

    if ($u && $p) {
        $hash = md5($p);
        
        // 1. Check in users table
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
            
            if (($roleName!='Super Admin') and ($roleName!='Admin') or ($roleName!='IT') and ($roleName!='SM')){
                // Record login activity
                recordLoginActivity($mysqli, $userRow['id'], 'user', session_id());
            }
            
            // Redirect based on role
            switch ($roleName) {
                case "Super Admin":
                    $_SESSION['supername']="Super Admin";
                    header("Location: Super_Admin/dashboard_md.php");
                    break;
                case "HR":
                    $_SESSION['hrname']="HR Admin";
                    header("Location: Admin/dashboard_hr.php");
                    break;
                case "TL":
                    $_SESSION['teamname']="Team Lead";
                    header("Location: Team_Lead/dashboard_tl.php");
                    break;
                case "TM":
                    $_SESSION['membername']="Team Member";
                    header("Location: Executive/dashboard_exec.php");
                    break;
                case "Admin":
                    $_SESSION['adminname']="Sub Admin";
                    header("Location: SubAdmin/dashboard.php");
                    break;
                case "Sub TL":
                    $_SESSION['membername']="Sub TL";
                    header("Location: SubTL/dashboard_exec.php");
                    break;
                case "SM":
                    $_SESSION['salesname']="Sales Market";
                    header("Location: Sales/dashboard.php");
                    break;
                case "IT":
                    $_SESSION['infoname']="Info Tech";
                    header("Location: system/dashboard.php");
                    break;
                default:
                    header("Location: index.php?error=invalid_role");
            }
            exit;
        }

        // 2. Check in employee_master table
        $stmt3 = $mysqli->prepare("
            SELECT e.employee_id AS id, e.employee_name AS employee_name, e.employee_username AS username, 
                   r.role_name, e.email, e.phone, e.joining_date, e.process, e.reporting_manager,
                   e.designation_id, e.team_id, e.status
            FROM employee_master e
            JOIN user_role_master r ON e.user_role_id = r.role_id
            WHERE e.employee_username = ? AND e.status = 'Active' AND e.password_hash = ?
            LIMIT 1
        ");
        $stmt3->bind_param("ss", $u, $hash);
        $stmt3->execute();
        $res3 = $stmt3->get_result();
        $empRow = $res3->fetch_assoc();
        $stmt3->close();

        if ($empRow) {
            session_regenerate_id(true);
            $_SESSION["uid"] = $empRow["id"];
            $_SESSION["username"] = $empRow["username"];
            $_SESSION["role_name"] = $empRow["role_name"];
            $_SESSION["ename"] = $empRow['employee_name'];
            
            // Store additional employee details in session
            $_SESSION["employee_details"] = [
                'email' => $empRow['email'],
                'phone' => $empRow['phone'],
                'joining_date' => $empRow['joining_date'],
                'process' => $empRow['process'],
                'reporting_manager' => $empRow['reporting_manager'],
                'designation_id' => $empRow['designation_id'],
                'team_id' => $empRow['team_id'],
                'status' => $empRow['status']
            ];

            // Record login activity
            recordLoginActivity($mysqli, $empRow["id"], 'employee', session_id());
            // Redirect based on role
            echo $empRow["role_name"];
            echo $empRow['team_id'];
            
            switch ($empRow["role_name"]) {
                case "Super Admin":
                    $_SESSION['supername']="Super Admin";
                    header("Location: Super_Admin/dashboard_md.php");
                    break;
                case "HR":
                    $_SESSION['hrname']="HR Admin";
                    header("Location: Admin/dashboard_hr.php");
                    break;
                case "TL":
                    $_SESSION['teamname']="Team Lead";
                    if ($empRow['team_id'] == 3) {
                        header("Location: xml/dashboard_tl.php");
                    } else {
                        header("Location: Team_Lead/dashboard_tl.php");
                    }
                    break;
                case "TM":
                    $_SESSION['teamname']="Team Member";
                    if ($empRow['team_id'] == 3) {
                      
                        header("Location: xml/dashboard_tl.php");
                    } else{
                        echo "test";
                       // die;
                        header("Location: Executive/dashboard_exec.php");
                    }
                    break;
                case "Admin":
                    $_SESSION['adminname']="Sub Admin";
                    header("Location: SubAdmin/dashboard.php");
                    break;
                case "Sub TL":
                    $_SESSION['membername']="Sub TL";
                    header("Location: SubTL/dashboard_exec.php");
                    break;
                case "SM":
                    $_SESSION['salesname']="Sales Market";
                    header("Location: Sales/dashboard.php");
                    break;
                case "IT":
                    $_SESSION['infoname']="Info Tech";
                    header("Location: system/dashboard.php");
                    break;
                default:
                    header("Location: index.php?error=invalid_role");
                    break;
            }
            exit;
        }
        $error = "Invalid username or password!";
    } else {
        $error = "Please enter username & password.";
    }
}

/**
 * Function to record login activity
 */
function recordLoginActivity($mysqli, $employeeId, $userType, $sessionId) {
    $ipAddress = $_SERVER['REMOTE_ADDR'];
    $userAgent = $_SERVER['HTTP_USER_AGENT'];
    
    $stmt = $mysqli->prepare("
        INSERT INTO employee_login_logs 
        (employee_id, login_time, ip_address, user_agent, session_id, user_type) 
        VALUES (?, NOW(), ?, ?, ?, ?)
    ");
    $stmt->bind_param("issss", $employeeId, $ipAddress, $userAgent, $sessionId, $userType);
    $stmt->execute();
    $stmt->close();
    
    // Store the login log ID in session for logout tracking
    $_SESSION['login_log_id'] = $mysqli->insert_id;
}

/**
 * Function to get basic employee details from employee_master
 */
function getEmployeeBasicDetails($mysqli, $employeeId) {
    $stmt = $mysqli->prepare("
        SELECT 
            employee_id, employee_name, employee_username, email, phone, 
            date_of_birth, joining_date, process, process1, reporting_manager, 
            user_role_id, status, pan_no, employee_name_pan, aadhar_no, 
            esi_name, esi_no, epd_name, uan_no, member_id, address, 
            referred_by, team_id, designation_id, shift, salary, tds_per,
            account_no, ifsc_code
        FROM employee_master 
        WHERE employee_id = ?
    ");
    $stmt->bind_param("i", $employeeId);
    $stmt->execute();
    $result = $stmt->get_result();
    $employeeDetails = $result->fetch_assoc();
    $stmt->close();
    
    return $employeeDetails;
}

/**
 * Function to check session timeout
 */
function checkSessionTimeout($mysqli) {
    if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity'] > 1800)) {
        // 30 minutes timeout
        if (isset($_SESSION['login_log_id']) && isset($_SESSION['uid'])) {
            $stmt = $mysqli->prepare("
                UPDATE employee_login_logs 
                SET logout_time = NOW(), status = 'timeout' 
                WHERE id = ? AND employee_id = ?
            ");
            $stmt->bind_param("ii", $_SESSION['login_log_id'], $_SESSION['uid']);
            $stmt->execute();
            $stmt->close();
        }
        
        session_unset();
        session_destroy();
        header("Location: index.php?error=session_timeout");
        exit;
    }
    $_SESSION['last_activity'] = time();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Tapasi Solutions LLP - Login</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="icon" type="image/png" href="slogo.png">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }

        :root {
            --primary: #3498db;
            --primary-dark: #2980b9;
            --secondary: #2c3e50;
            --light: #f5f7fa;
            --dark: #34495e;
            --gray: #6c757d;
            --gray-light: #8c959d;
            --gray-dark: #5a6268;
            --white: #ffffff;
            --error: #e74c3c;
        }

        body {
            background-color: #e9ecef;
            color: var(--secondary);
            line-height: 1.6;
            min-height: 100vh;
            display: flex;
            justify-content: center;
            align-items: center;
            padding: 20px;
        }

        .login-container {
            display: flex;
            width: 100%;
            max-width: 1000px;
            min-height: 600px;
            border-radius: 12px;
            overflow: hidden;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.1);
        }

        /* Left Panel - Branding */
        .brand-panel {
            flex: 1;
            background: linear-gradient(135deg, var(--secondary) 0%, var(--dark) 100%);
            color: var(--white);
            padding: 50px 40px;
            display: flex;
            flex-direction: column;
            justify-content: center;
            position: relative;
            overflow: hidden;
        }

        .brand-panel::before {
            content: '';
            position: absolute;
            top: -50px;
            right: -50px;
            width: 200px;
            height: 200px;
            border-radius: 50%;
            background: rgba(255, 255, 255, 0.05);
        }

        .brand-panel::after {
            content: '';
            position: absolute;
            bottom: -80px;
            left: -80px;
            width: 250px;
            height: 250px;
            border-radius: 50%;
            background: rgba(255, 255, 255, 0.05);
        }

        .logo {
            margin-bottom: 30px;
            position: relative;
            z-index: 1;
        }

        .logo img {
            max-width: 200px;
            height: auto;
        }

        .logo h1 {
            font-size: 32px;
            font-weight: 700;
            margin-bottom: 5px;
            letter-spacing: 1px;
        }

        .logo h2 {
            font-size: 18px;
            font-weight: 400;
            opacity: 0.9;
            letter-spacing: 2px;
        }

        .welcome-text {
            margin-top: 40px;
            position: relative;
            z-index: 1;
        }

        .welcome-text h3 {
            font-size: 28px;
            font-weight: 300;
            margin-bottom: 15px;
        }

        .welcome-text p {
            font-size: 16px;
            opacity: 0.8;
            line-height: 1.8;
        }

        /* Right Panel - Login Form */
        .login-panel {
            flex: 1;
            background-color: var(--white);
            padding: 60px 50px;
            display: flex;
            flex-direction: column;
            justify-content: center;
        }

        .login-header {
            margin-bottom: 40px;
            text-align: center;
        }

        .login-header h2 {
            font-size: 28px;
            color: var(--secondary);
            margin-bottom: 10px;
        }

        .login-header p {
            color: var(--gray);
            font-size: 16px;
        }

        .error-message {
            background-color: rgba(231, 76, 60, 0.1);
            border: 1px solid rgba(231, 76, 60, 0.3);
            color: var(--error);
            padding: 12px;
            border-radius: 6px;
            margin-bottom: 20px;
            text-align: center;
            font-weight: 500;
        }

        .login-form {
            width: 100%;
        }

        .form-group {
            margin-bottom: 25px;
            position: relative;
        }

        .form-group label {
            display: block;
            margin-bottom: 8px;
            font-weight: 500;
            color: var(--dark);
        }

        .input-with-icon {
            position: relative;
        }

        .input-with-icon i {
            position: absolute;
            left: 15px;
            top: 50%;
            transform: translateY(-50%);
            color: var(--gray);
        }

        .form-control {
            width: 100%;
            padding: 14px 15px 14px 45px;
            border: 1px solid #e0e0e0;
            border-radius: 6px;
            font-size: 16px;
            transition: all 0.3s ease;
            background-color: #f8f9fa;
        }

        .form-control:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(52, 152, 219, 0.2);
            background-color: var(--white);
        }

        .password-toggle {
            position: absolute;
            right: 15px;
            top: 50%;
            transform: translateY(-50%);
            color: var(--gray);
            cursor: pointer;
        }

        .form-options {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 25px;
        }

        .remember-me {
            display: flex;
            align-items: center;
        }

        .remember-me input {
            margin-right: 8px;
        }

        .forgot-password {
            color: var(--primary);
            text-decoration: none;
            font-size: 14px;
            transition: color 0.3s ease;
        }

        .forgot-password:hover {
            color: var(--primary-dark);
            text-decoration: underline;
        }

        .btn-login {
            width: 100%;
            padding: 14px;
            background-color: var(--primary);
            color: var(--white);
            border: none;
            border-radius: 6px;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            transition: background-color 0.3s ease;
            display: flex;
            justify-content: center;
            align-items: center;
        }

        .btn-login:hover {
            background-color: var(--primary-dark);
        }

        .btn-login i {
            margin-left: 8px;
        }

        .login-footer {
            margin-top: 30px;
            text-align: center;
            color: var(--gray);
            font-size: 14px;
        }

        /* Responsive Design */
        @media (max-width: 768px) {
            .login-container {
                flex-direction: column;
                max-width: 450px;
            }
            
            .brand-panel {
                padding: 40px 30px;
            }
            
            .login-panel {
                padding: 40px 30px;
            }
        }

        @media (max-width: 480px) {
            body {
                padding: 10px;
            }
            
            .brand-panel {
                padding: 30px 20px;
            }
            
            .login-panel {
                padding: 30px 20px;
            }
            
            .logo h1 {
                font-size: 28px;
            }
            
            .logo h2 {
                font-size: 16px;
            }
            
            .welcome-text h3 {
                font-size: 24px;
            }
        }
    </style>
</head>
<body>
    <div class="login-container">
        <!-- Left Panel - Branding -->
        <div class="brand-panel">
            <div class="logo">
               <center> <img src="tlogo.jpg" alt="Company Logo" /></center>
            </div>
            <div class="welcome-text">
                <h3>Welcome Back</h3>
                <p>Access your account to manage your business operations and track performance metrics.</p>
            </div>
        </div>
        
        <!-- Right Panel - Login Form -->
        <div class="login-panel">
            <div class="login-header">
                <h2>Account Login</h2>
                <p>Enter your credentials to continue</p>
            </div>
            
            <?php if (!empty($error)): ?>
                <div class="error-message">
                    <?php echo htmlspecialchars($error); ?>
                </div>
            <?php endif; ?>
            
            <form class="login-form" id="loginForm" method="POST" action="">
                <div class="form-group">
                    <label for="username">Username</label>
                    <div class="input-with-icon">
                        <i class="fas fa-user"></i>
                        <input type="text" id="username" name="username" class="form-control" placeholder="Enter your username" required value="<?php echo isset($_POST['username']) ? htmlspecialchars($_POST['username']) : ''; ?>">
                    </div>
                </div>
                
                <div class="form-group">
                    <label for="password">Password</label>
                    <div class="input-with-icon">
                        <i class="fas fa-lock"></i>
                        <input type="password" id="password" name="password" class="form-control" placeholder="Enter your password" required>
                        <span class="password-toggle" id="passwordToggle">
                            <i class="fas fa-eye"></i>
                        </span>
                    </div>
                </div>
                
                <!--div class="form-options">
                    <div class="remember-me">
                        <input type="checkbox" id="remember" name="remember">
                        <label for="remember">Remember me</label>
                    </div>
                    <a href="#" class="forgot-password">Forgot Password?</a>
                </div-->
                
                <button type="submit" class="btn-login">
                    Login <i class="fas fa-arrow-right"></i>
                </button>
                
                <div class="login-footer">
                  <p>&copy; <?php echo date("Y"); ?> Tapasi Solutions LLP. All rights reserved.</p>
                </div>
            </form>
        </div>
    </div>

    <script>
        // Password visibility toggle
        const passwordToggle = document.getElementById('passwordToggle');
        const passwordInput = document.getElementById('password');
        
        if (passwordToggle && passwordInput) {
            passwordToggle.addEventListener('click', function() {
                const type = passwordInput.getAttribute('type') === 'password' ? 'text' : 'password';
                passwordInput.setAttribute('type', type);
                
                // Toggle eye icon
                const eyeIcon = this.querySelector('i');
                if (type === 'text') {
                    eyeIcon.classList.remove('fa-eye');
                    eyeIcon.classList.add('fa-eye-slash');
                } else {
                    eyeIcon.classList.remove('fa-eye-slash');
                    eyeIcon.classList.add('fa-eye');
                }
            });
        }
    </script>
</body>
</html>
