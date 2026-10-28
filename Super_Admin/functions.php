<?php
/**
 * Common functions for the application
 */

/**
 * Record login activity
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
 * Get basic employee details from employee_master
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
 * Check session timeout
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

/**
 * Get login history for an employee
 */
function getLoginHistory($mysqli, $employeeId, $limit = 10) {
    $stmt = $mysqli->prepare("
        SELECT login_time, logout_time, ip_address, status 
        FROM employee_login_logs 
        WHERE employee_id = ? 
        ORDER BY login_time DESC 
        LIMIT ?
    ");
    $stmt->bind_param("ii", $employeeId, $limit);
    $stmt->execute();
    $result = $stmt->get_result();
    $history = $result->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    
    return $history;
}
?>