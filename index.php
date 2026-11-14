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
if (isset($_GET['error'])) {
    if ($_GET['error'] === 'session_timeout') {
        $error = "Your session has expired. Please sign in again.";
    } elseif ($_GET['error'] === 'invalid_role') {
        $error = "Your user role is not authorized or recognized. Please contact system admin.";
    }
}

if (($_SERVER["REQUEST_METHOD"] ?? '') === "POST") {
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
                case "sales":
                    $_SESSION['sales_exec']="Sales Executive";
                    $_SESSION['salesname']="Sales Executive";
                    header("Location: Sales_Executive/dashboard.php");
                    break;
                case "IT":
                    if ($u === 'system') {
                        $_SESSION['infoname']="Info Tech";
                        header("Location: system/dashboard.php");
                    } else {
                        $_SESSION['itname']="IT";
                        $_SESSION['infoname']="Info Tech";
                        header("Location: IT/dashboard.php");
                    }
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
                    } else if ($empRow['team_id'] == 9) {
                        $_SESSION['sales_exec']="Sales Executive";
                        $_SESSION['salesname']="Sales Executive";
                        header("Location: Sales_Executive/dashboard.php");
                    } else if ($empRow['team_id'] == 8) {
                        $_SESSION['itname'] = "IT";
                        header("Location: IT/dashboard.php");
                    } else {
                        header("Location: Executive/dashboard_exec.php");
                    }
                    break;
                case "sales":
                    $_SESSION['sales_exec']="Sales Executive";
                    $_SESSION['salesname']="Sales Executive";
                    header("Location: Sales_Executive/dashboard.php");
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
                    if ($u === 'system') {
                        $_SESSION['infoname']="Info Tech";
                        header("Location: system/dashboard.php");
                    } else {
                        $_SESSION['itname']="IT";
                        $_SESSION['infoname']="Info Tech";
                        header("Location: IT/dashboard.php");
                    }
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
    <title>Tapasi Solutions LLP - Enterprise Portal Login</title>
    <!-- Fonts & Icons -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="custom_style.css">
    <script src="erp_toast.js" defer></script>
    <link rel="icon" type="image/png" href="slogo.png">
    
    <style>
        /* =========================================================================
           SENIOR DEVELOPER STYLING - TAPASI ERP ENTERPRISE LOGIN PORTAL
           ========================================================================= */
        *, *::before, *::after {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        :root {
            --primary: #3498db;
            --primary-hover: #2980b9;
            --primary-light: #ebf5fb;
            --primary-border: #aed6f1;
            --ash-dark: #2c3e50;
            --ash-medium: #34495e;
            --ash-deep: #243342;
            --ash-light: #5a6a7a;
            --ash-bg: #e9ecef;
            --slate-50: #f8fafc;
            --slate-100: #f1f5f9;
            --slate-200: #e2e8f0;
            --slate-400: #94a3b8;
            --slate-500: #64748b;
            --slate-600: #475569;
            --slate-700: #334155;
            --emerald-500: #10b981;
            --danger-500: #e74c3c;
            --danger-50: rgba(231, 76, 60, 0.1);
            --danger-border: rgba(231, 76, 60, 0.3);
            --danger-text: #c0392b;
        }

        body {
            font-family: 'Plus Jakarta Sans', system-ui, -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background-color: var(--ash-bg);
            background-image: 
                radial-gradient(at 0% 0%, rgba(52, 152, 219, 0.08) 0px, transparent 50%),
                radial-gradient(at 100% 100%, rgba(44, 62, 80, 0.08) 0px, transparent 50%),
                radial-gradient(#d1d5db 1.2px, transparent 1.2px);
            background-size: 100% 100%, 100% 100%, 26px 26px;
            color: var(--ash-dark);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 24px;
            -webkit-font-smoothing: antialiased;
            -moz-osx-font-smoothing: grayscale;
            text-rendering: optimizeLegibility;
        }

        @keyframes cardEntrance {
            from {
                opacity: 0;
                transform: translateY(18px) scale(0.985);
            }
            to {
                opacity: 1;
                transform: translateY(0) scale(1);
            }
        }

        @keyframes pulseDot {
            0%, 100% {
                opacity: 1;
                transform: scale(1);
            }
            50% {
                opacity: 0.45;
                transform: scale(1.2);
            }
        }

        @keyframes alertShake {
            0%, 100% { transform: translateX(0); }
            20%, 60% { transform: translateX(-6px); }
            40%, 80% { transform: translateX(6px); }
        }

        /* Master Card Container */
        .login-card {
            width: 100%;
            max-width: 1060px;
            min-height: 640px;
            display: flex;
            background: #ffffff;
            border-radius: 20px;
            overflow: hidden;
            border: 1px solid rgba(226, 232, 240, 0.85);
            box-shadow: 
                0 20px 50px rgba(44, 62, 80, 0.16),
                0 8px 20px rgba(44, 62, 80, 0.06),
                0 0 0 1px rgba(44, 62, 80, 0.04);
            animation: cardEntrance 0.5s cubic-bezier(0.16, 1, 0.3, 1) both;
            position: relative;
        }

        /* =========================================================================
           LEFT PANEL - BRAND & ENTERPRISE SHOWCASE (EXACT ASH SLATE #2c3e50)
           ========================================================================= */
        .brand-hero {
            flex: 1.05;
            background: linear-gradient(145deg, var(--ash-dark) 0%, var(--ash-medium) 55%, var(--ash-deep) 100%);
            color: #ffffff;
            padding: 54px 44px;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            position: relative;
            overflow: hidden;
        }

        /* Subtle Translucent Circular Accents */
        .brand-hero::before {
            content: '';
            position: absolute;
            top: -60px;
            right: -60px;
            width: 240px;
            height: 240px;
            border-radius: 50%;
            background: rgba(255, 255, 255, 0.05);
            pointer-events: none;
        }

        .brand-hero::after {
            content: '';
            position: absolute;
            bottom: -80px;
            left: -80px;
            width: 280px;
            height: 280px;
            border-radius: 50%;
            background: rgba(255, 255, 255, 0.05);
            pointer-events: none;
        }

        .brand-top {
            position: relative;
            z-index: 2;
        }

        /* Status Indicator Pill */
        .system-status-pill {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 6px 14px;
            background: rgba(255, 255, 255, 0.09);
            border: 1px solid rgba(255, 255, 255, 0.16);
            backdrop-filter: blur(8px);
            border-radius: 30px;
            font-size: 0.78rem;
            font-weight: 600;
            color: #ecf0f1;
            margin-bottom: 24px;
            letter-spacing: 0.3px;
        }

        .status-dot {
            width: 8px;
            height: 8px;
            border-radius: 50%;
            background-color: var(--emerald-500);
            box-shadow: 0 0 10px var(--emerald-500);
            animation: pulseDot 2.2s infinite ease-in-out;
        }

        /* Logo Card */
        .brand-logo-card {
            background: #ffffff;
            border-radius: 12px;
            padding: 12px 22px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            box-shadow: 
                0 8px 24px rgba(0, 0, 0, 0.22),
                0 0 0 1px rgba(255, 255, 255, 0.15);
            margin-bottom: 28px;
            transition: transform 0.25s ease;
        }

        .brand-logo-card:hover {
            transform: translateY(-2px);
        }

        .brand-logo-card img {
            max-height: 48px;
            width: auto;
            display: block;
            object-fit: contain;
        }

        /* Hero Text */
        .hero-heading {
            font-size: 1.85rem;
            font-weight: 800;
            line-height: 1.25;
            letter-spacing: -0.02em;
            margin-bottom: 12px;
            color: #ffffff;
        }

        .hero-desc {
            font-size: 0.92rem;
            color: rgba(255, 255, 255, 0.85);
            line-height: 1.65;
            margin-bottom: 30px;
        }

        /* Enterprise Feature Highlights (Retained Senior UI Content) */
        .feature-list {
            display: flex;
            flex-direction: column;
            gap: 12px;
            position: relative;
            z-index: 2;
        }

        .feature-item {
            display: flex;
            align-items: flex-start;
            gap: 14px;
            padding: 12px 14px;
            background: rgba(255, 255, 255, 0.06);
            border: 1px solid rgba(255, 255, 255, 0.1);
            border-radius: 12px;
            transition: all 0.2s ease;
        }

        .feature-item:hover {
            background: rgba(255, 255, 255, 0.1);
            border-color: rgba(255, 255, 255, 0.16);
            transform: translateX(3px);
        }

        .feature-icon-box {
            width: 34px;
            height: 34px;
            border-radius: 8px;
            background: rgba(52, 152, 219, 0.22);
            border: 1px solid rgba(52, 152, 219, 0.4);
            display: flex;
            align-items: center;
            justify-content: center;
            color: #5dade2;
            font-size: 0.88rem;
            flex-shrink: 0;
            margin-top: 2px;
        }

        .feature-text strong {
            display: block;
            font-size: 0.86rem;
            font-weight: 700;
            color: #ffffff;
            margin-bottom: 2px;
        }

        .feature-text span {
            font-size: 0.77rem;
            color: rgba(255, 255, 255, 0.78);
            line-height: 1.45;
            display: block;
        }

        /* Hero Security Footer */
        .brand-security-footer {
            margin-top: 32px;
            padding-top: 18px;
            border-top: 1px solid rgba(255, 255, 255, 0.1);
            display: flex;
            align-items: center;
            gap: 10px;
            font-size: 0.78rem;
            color: rgba(255, 255, 255, 0.82);
            position: relative;
            z-index: 2;
        }

        .brand-security-footer i {
            color: #5dade2;
            font-size: 0.95rem;
        }

        /* =========================================================================
           RIGHT PANEL - AUTHENTICATION FORM (CLEAN WHITE & #3498db ACCENTS)
           ========================================================================= */
        .auth-panel {
            flex: 1;
            background: #ffffff;
            padding: 54px 48px;
            display: flex;
            flex-direction: column;
            justify-content: center;
            position: relative;
        }

        .auth-header {
            margin-bottom: 28px;
        }

        .auth-eyebrow {
            display: inline-flex;
            align-items: center;
            font-size: 0.72rem;
            font-weight: 800;
            color: var(--primary);
            letter-spacing: 1.2px;
            text-transform: uppercase;
            margin-bottom: 6px;
        }

        .auth-title {
            font-size: 1.75rem;
            font-weight: 800;
            color: var(--ash-dark);
            letter-spacing: -0.02em;
            margin-bottom: 8px;
        }

        .auth-subtitle {
            font-size: 0.88rem;
            color: var(--slate-500);
            line-height: 1.5;
        }

        /* Error Alert Box */
        .auth-alert-error {
            background-color: var(--danger-50);
            border: 1px solid var(--danger-border);
            color: var(--danger-text);
            padding: 12px 16px;
            border-radius: 10px;
            margin-bottom: 22px;
            font-size: 0.86rem;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 10px;
            animation: alertShake 0.4s ease-in-out;
        }

        .auth-alert-error i {
            font-size: 1rem;
            color: var(--danger-500);
            flex-shrink: 0;
        }

        /* Form Groups */
        .auth-form {
            width: 100%;
        }

        .form-row-group {
            margin-bottom: 20px;
            position: relative;
        }

        .auth-label {
            display: flex;
            align-items: center;
            margin-bottom: 7px;
            font-size: 0.82rem;
            font-weight: 700;
            color: var(--ash-medium);
            letter-spacing: 0.2px;
        }

        .auth-input-wrapper {
            position: relative;
            display: flex;
            align-items: center;
        }

        .auth-input-icon {
            position: absolute;
            left: 14px;
            color: var(--slate-400);
            font-size: 0.95rem;
            pointer-events: none;
            transition: color 0.2s ease;
            z-index: 2;
        }

        .auth-input {
            width: 100%;
            height: 48px;
            padding: 0 44px 0 42px;
            background-color: var(--slate-50);
            border: 1.5px solid var(--slate-200);
            border-radius: 10px;
            font-size: 0.92rem;
            font-family: inherit;
            color: var(--ash-dark);
            font-weight: 500;
            transition: all 0.2s ease;
        }

        .auth-input::placeholder {
            color: #94a3b8;
            font-weight: 400;
        }

        .auth-input:focus {
            outline: none;
            background-color: #ffffff;
            border-color: var(--primary);
            box-shadow: 0 0 0 3.5px rgba(52, 152, 219, 0.22);
        }

        .auth-input-wrapper:focus-within .auth-input-icon {
            color: var(--primary);
        }

        /* Password Eye Toggle */
        .auth-eye-btn {
            position: absolute;
            right: 12px;
            background: none;
            border: none;
            padding: 8px;
            color: var(--slate-400);
            cursor: pointer;
            border-radius: 6px;
            font-size: 0.95rem;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: color 0.2s ease;
            z-index: 2;
        }

        .auth-eye-btn:hover {
            color: var(--ash-dark);
        }

        /* Caps Lock Warning Pill */
        .caps-alert {
            display: flex;
            align-items: center;
            gap: 6px;
            margin-top: 6px;
            padding: 5px 10px;
            background: #fffbeb;
            border: 1px solid #fef3c7;
            border-radius: 6px;
            font-size: 0.74rem;
            font-weight: 600;
            color: #b45309;
        }

        /* Form Options (Remember Me) */
        .form-options-row {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 24px;
        }

        .auth-checkbox-label {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            font-size: 0.82rem;
            font-weight: 600;
            color: var(--slate-600);
            cursor: pointer;
            user-select: none;
        }

        .auth-checkbox-label input[type="checkbox"] {
            width: 16px;
            height: 16px;
            border-radius: 4px;
            accent-color: var(--primary);
            cursor: pointer;
        }

        /* Sign In Submit Button (#3498db Blue) */
        .auth-submit-btn {
            width: 100%;
            height: 50px;
            background: linear-gradient(135deg, var(--primary) 0%, var(--primary-hover) 100%);
            color: #ffffff;
            border: none;
            border-radius: 10px;
            font-size: 0.95rem;
            font-weight: 700;
            font-family: inherit;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            transition: all 0.25s cubic-bezier(0.4, 0, 0.2, 1);
            box-shadow: 0 4px 14px rgba(52, 152, 219, 0.32);
        }

        .auth-submit-btn:hover {
            background: linear-gradient(135deg, var(--primary-hover) 0%, #1f618d 100%);
            transform: translateY(-1.5px);
            box-shadow: 0 8px 22px rgba(52, 152, 219, 0.42);
        }

        .auth-submit-btn:active {
            transform: translateY(0);
            box-shadow: 0 2px 8px rgba(37, 99, 235, 0.25);
        }

        .auth-submit-btn .btn-icon {
            transition: transform 0.2s ease;
        }

        .auth-submit-btn:hover .btn-icon {
            transform: translateX(4px);
        }

        .auth-submit-btn:disabled {
            opacity: 0.8;
            cursor: not-allowed;
            transform: none !important;
        }

        /* Footer Info */
        .auth-footer {
            margin-top: 32px;
            text-align: center;
            font-size: 0.78rem;
            color: var(--slate-400);
            line-height: 1.6;
        }

        .auth-footer a {
            color: var(--primary);
            text-decoration: none;
            font-weight: 600;
        }

        .auth-footer a:hover {
            text-decoration: underline;
        }

        /* =========================================================================
           RESPONSIVE BREAKPOINTS
           ========================================================================= */
        @media (max-width: 920px) {
            .login-card {
                flex-direction: column;
                max-width: 500px;
                min-height: auto;
            }

            .brand-hero {
                padding: 40px 32px;
            }

            .feature-list {
                display: none;
            }

            .brand-security-footer {
                margin-top: 20px;
                padding-top: 14px;
            }

            .auth-panel {
                padding: 40px 32px;
            }
        }

        @media (max-width: 480px) {
            body {
                padding: 12px;
            }

            .login-card {
                border-radius: 18px;
            }

            .brand-hero {
                padding: 30px 22px;
            }

            .hero-heading {
                font-size: 1.5rem;
            }

            .auth-panel {
                padding: 32px 22px;
            }

            .auth-title {
                font-size: 1.45rem;
            }
        }
    </style>
</head>
<body>

    <div class="login-card">
        <!-- =========================================================================
             LEFT PANEL: ENTERPRISE BRANDING & OPERATIONS SHOWCASE
             ========================================================================= -->
        <div class="brand-hero">
            <div class="brand-top">
                <!-- System Status Indicator -->
                <div class="system-status-pill">
                    <span class="status-dot"></span>
                    <span>System Operational &bull; Enterprise Portal v2.6</span>
                </div>

                <!-- Pristine Logo Card -->
                <div class="brand-logo-card">
                    <img src="tlogo.jpg" alt="Tapasi Solutions Logo">
                </div>

                <!-- Hero Copy -->
                <h1 class="hero-heading">Welcome to Tapasi ERP</h1>
                <p class="hero-desc">
                    Unified operations management, workflow tracking, publishing lifecycle, and real-time enterprise performance analytics.
                </p>

                <!-- Feature Highlights -->
                <div class="feature-list">
                    <div class="feature-item">
                        <div class="feature-icon-box">
                            <i class="fas fa-layer-group"></i>
                        </div>
                        <div class="feature-text">
                            <strong>End-to-End Workflow Tracking</strong>
                            <span>Automated task lifecycle progression across CE, LE, TS, QC, and AP teams.</span>
                        </div>
                    </div>

                    <div class="feature-item">
                        <div class="feature-icon-box">
                            <i class="fas fa-user-shield"></i>
                        </div>
                        <div class="feature-text">
                            <strong>Role-Based Access Governance</strong>
                            <span>Granular authorization configured for administrators, team leaders, and executives.</span>
                        </div>
                    </div>

                    <div class="feature-item">
                        <div class="feature-icon-box">
                            <i class="fas fa-chart-line"></i>
                        </div>
                        <div class="feature-text">
                            <strong>Real-Time Production Metrics</strong>
                            <span>Live job status reporting, turnaround SLAs, and operational analytics.</span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Security Trust Badge -->
            <div class="brand-security-footer">
                <i class="fas fa-shield-halved"></i>
                <span>256-bit TLS Encrypted Session &bull; Secured Enterprise Network</span>
            </div>
        </div>

        <!-- =========================================================================
             RIGHT PANEL: SENIOR DEVELOPER AUTHENTICATION FORM
             ========================================================================= -->
        <div class="auth-panel">
            <div class="auth-header">
                <div class="auth-eyebrow">
                    <i class="fas fa-lock me-1"></i> SECURE PORTAL ACCESS
                </div>
                <h2 class="auth-title">Account Sign In</h2>
                <p class="auth-subtitle">Enter your employee username and password to proceed.</p>
            </div>

            <!-- Error Notification Banner -->
            <?php if (!empty($error)): ?>
                <div class="auth-alert-error" role="alert">
                    <i class="fas fa-circle-exclamation"></i>
                    <span><?= htmlspecialchars($error) ?></span>
                </div>
            <?php endif; ?>

            <!-- Login Form (100% Functionality Preserved) -->
            <form class="auth-form" id="loginForm" method="POST" action="">
                <!-- Username / Employee ID -->
                <div class="form-row-group">
                    <label for="username" class="auth-label">
                        <i class="fas fa-user-circle me-1" style="color: var(--primary);"></i> Username or Employee ID
                    </label>
                    <div class="auth-input-wrapper">
                        <i class="fas fa-user auth-input-icon"></i>
                        <input type="text" 
                               id="username" 
                               name="username" 
                               class="auth-input" 
                               placeholder="e.g. TPS001 or admin" 
                               autocomplete="username" 
                               required 
                               value="<?= isset($_POST['username']) ? htmlspecialchars($_POST['username']) : '' ?>">
                    </div>
                </div>

                <!-- Password -->
                <div class="form-row-group">
                    <div class="d-flex justify-content-between align-items-center mb-1">
                        <label for="password" class="auth-label mb-0">
                            <i class="fas fa-key me-1" style="color: var(--primary);"></i> Password
                        </label>
                    </div>
                    <div class="auth-input-wrapper">
                        <i class="fas fa-lock auth-input-icon"></i>
                        <input type="password" 
                               id="password" 
                               name="password" 
                               class="auth-input" 
                               placeholder="••••••••••••" 
                               autocomplete="current-password" 
                               required>
                        <button type="button" 
                                class="auth-eye-btn" 
                                id="passwordToggle" 
                                title="Toggle password visibility" 
                                aria-label="Toggle password visibility">
                            <i class="fas fa-eye" id="eyeIcon"></i>
                        </button>
                    </div>
                    <!-- Caps Lock Alert -->
                    <div class="caps-alert" id="capsAlert" style="display:none;">
                        <i class="fas fa-arrow-up-long"></i>
                        <span>Caps Lock is ON</span>
                    </div>
                </div>

                <!-- Remember Me Option -->
                <div class="form-options-row">
                    <label class="auth-checkbox-label" for="rememberMe">
                        <input type="checkbox" id="rememberMe">
                        <span>Remember my username</span>
                    </label>
                </div>

                <!-- Sign In Action Button -->
                <button type="submit" class="auth-submit-btn" id="submitBtn">
                    <span id="btnText">Sign In to Dashboard</span>
                    <i class="fas fa-arrow-right btn-icon" id="btnIcon"></i>
                </button>

                <!-- Footer Copyright & Support -->
                <div class="auth-footer">
                    <p>&copy; <?= date("Y") ?> Tapasi Solutions LLP. All rights reserved.</p>
                    <p style="margin-top: 4px; font-size: 0.74rem;">
                        Authorized personnel only &bull; Need help? Contact System Administrator
                    </p>
                </div>
            </form>
        </div>
    </div>

    <!-- Client-Side Micro-Interactions -->
    <script>
    document.addEventListener('DOMContentLoaded', function() {
        const usernameInput = document.getElementById('username');
        const passwordInput = document.getElementById('password');
        const passwordToggle = document.getElementById('passwordToggle');
        const eyeIcon = document.getElementById('eyeIcon');
        const capsAlert = document.getElementById('capsAlert');
        const rememberMe = document.getElementById('rememberMe');
        const loginForm = document.getElementById('loginForm');
        const submitBtn = document.getElementById('submitBtn');
        const btnText = document.getElementById('btnText');
        const btnIcon = document.getElementById('btnIcon');

        // 1. Restore remembered username from localStorage
        const savedUsername = localStorage.getItem('tapasi_saved_username');
        if (savedUsername && !usernameInput.value) {
            usernameInput.value = savedUsername;
            rememberMe.checked = true;
            if (passwordInput) passwordInput.focus();
        } else if (usernameInput && !usernameInput.value) {
            usernameInput.focus();
        }

        // 2. Toggle Password Visibility
        if (passwordToggle && passwordInput && eyeIcon) {
            passwordToggle.addEventListener('click', function(e) {
                e.preventDefault();
                const isPassword = passwordInput.getAttribute('type') === 'password';
                passwordInput.setAttribute('type', isPassword ? 'text' : 'password');
                
                if (isPassword) {
                    eyeIcon.classList.remove('fa-eye');
                    eyeIcon.classList.add('fa-eye-slash');
                    passwordToggle.setAttribute('title', 'Hide password');
                } else {
                    eyeIcon.classList.remove('fa-eye-slash');
                    eyeIcon.classList.add('fa-eye');
                    passwordToggle.setAttribute('title', 'Show password');
                }
            });
        }

        // 3. Detect Caps Lock on Password Field
        if (passwordInput && capsAlert) {
            ['keydown', 'keyup'].forEach(function(evt) {
                passwordInput.addEventListener(evt, function(e) {
                    if (e.getModifierState && e.getModifierState('CapsLock')) {
                        capsAlert.style.display = 'flex';
                    } else {
                        capsAlert.style.display = 'none';
                    }
                });
            });
            passwordInput.addEventListener('blur', function() {
                capsAlert.style.display = 'none';
            });
        }

        // 4. Form Submission Handling (Loading state & Remember Me)
        if (loginForm && submitBtn) {
            loginForm.addEventListener('submit', function() {
                // Save or clear remembered username
                if (rememberMe && rememberMe.checked && usernameInput.value) {
                    localStorage.setItem('tapasi_saved_username', usernameInput.value.trim());
                } else {
                    localStorage.removeItem('tapasi_saved_username');
                }

                // Smooth submitting tactile feedback
                submitBtn.disabled = true;
                if (btnText) btnText.textContent = 'Authenticating...';
                if (btnIcon) {
                    btnIcon.className = 'fas fa-spinner fa-spin ms-2';
                }
            });
        }
    });
    </script>
</body>
</html>
