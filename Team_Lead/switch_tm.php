<?php
session_start();
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
  require 'db.php';
  
// Record logout activity before destroying session
if (isset($_SESSION['login_log_id']) && isset($_SESSION['uid'])) {
    
    $stmt = $mysqli->prepare("
        UPDATE employee_login_logs 
        SET logout_time = NOW(), status = 'logout' 
        WHERE id = ? AND employee_id = ?
    ");
    $stmt->bind_param("ii", $_SESSION['login_log_id'], $_SESSION['uid']);
    $stmt->execute();
    $stmt->close();
}

// Destroy session
session_unset();
session_destroy();

session_start();

// 2. Check in employee_master table
        $stmt3 = $mysqli->prepare("
            SELECT e.employee_id AS id, e.employee_name AS employee_name, e.employee_username AS username, 
                   r.role_name, e.email, e.phone, e.joining_date, e.process, e.reporting_manager,
                   e.designation_id, e.team_id, e.status
            FROM employee_master e
            JOIN user_role_master r ON e.user_role_id = r.role_id
            WHERE e.employee_username = 'TPS002' AND e.status = 'Active'
            LIMIT 1
        ");
        
        $stmt3->execute();
        $res3 = $stmt3->get_result();
        $empRow = $res3->fetch_assoc();
        $stmt3->close();

        if ($empRow) {
           // session_regenerate_id(true);
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

           
}

 // Record login activity
            recordLoginActivity($mysqli, $empRow["id"], 'employee', session_id());
           // echo $_SESSION["uid"] = $empRow["id"];
            //die;
              header("Location: ../Executive/dashboard_exec.php");
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
?>