<?php
session_start();

// Record logout activity before destroying session
if (isset($_SESSION['login_log_id']) && isset($_SESSION['uid'])) {
    require 'db.php';
    
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

// Redirect to login page
header("Location: index.php");
exit;
?>