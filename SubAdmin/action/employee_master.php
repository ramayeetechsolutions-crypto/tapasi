<?php
// action/employee_master.php
ini_set('display_errors', 1);

// 2. Report all errors
error_reporting(E_ALL);
require '../db.php'; // Adjust path to your db.php
if (session_status() === PHP_SESSION_NONE) { 
    session_start(); 
}

// Helper functions (assuming they are not in a global included file)
function h($s) { 
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); 
}

function redirect_to($base, $params = []) {
    if (!empty($params)) $base .= '?' . http_build_query($params);
    header("Location: $base");
    exit;
}

// --- CORE ACTION HANDLER ---

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'add' || $action === 'edit') {
        // Essential fields for basic operation (adjust as needed)
        $employee_name = trim($_POST['employee_name'] ?? '');
        $employee_username = trim($_POST['employee_username'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $phone = trim($_POST['phone'] ?? '');
        $joining_date = $_POST['joining_date'] ?? '';
        $designation_id = (int)($_POST['designation_id'] ?? 0); // Assuming FK
        $user_role_id = (int)($_POST['user_role_id'] ?? 0); // Assuming FK
        $status = $_POST['status'] ?? 'Active';
        
        // Basic Validation
        if ($employee_name === '' || $employee_username === '' || $email === '' || $joining_date === '') {
            redirect_to('../employee_entry.php', ['msg' => 'Required fields are missing.', 'type' => 'danger', 'id' => ($action === 'edit' ? $_POST['employee_id'] : null)]);
        }
        
        if ($action === 'add') {
            // Check for duplicate username
            $check_stmt = $mysqli->prepare("SELECT employee_id FROM employee_master WHERE employee_username = ?");
            $check_stmt->bind_param("s", $employee_username);
            $check_stmt->execute();
            $check_stmt->store_result();
            
            if ($check_stmt->num_rows > 0) {
                $check_stmt->close();
                redirect_to('../employee_entry.php', ['msg' => 'Username already exists.', 'type' => 'danger']);
            }
            $check_stmt->close();

            // Insert new employee
            // IMPORTANT: Never store plain passwords! This example uses a placeholder.
            $password_hash = password_hash('defaultpass', PASSWORD_DEFAULT); // Set a default password on add

            $sql = "INSERT INTO employee_master (employee_name, employee_username, password_hash, email, phone, joining_date, designation_id, role_id, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)";
            $stmt = $mysqli->prepare($sql);
            $stmt->bind_param("ssssssiis", $employee_name, $employee_username, $password_hash, $email, $phone, $joining_date, $designation_id, $user_role_id, $status);
            
            if ($stmt->execute()) {
                redirect_to('../employee_master.php', ['msg' => "Employee '$employee_name' added successfully", 'type' => 'success']);
            } else {
                redirect_to('../employee_entry.php', ['msg' => 'Error adding employee: ' . $stmt->error, 'type' => 'danger']);
            }
            $stmt->close();
        }
        
        if ($action === 'edit') {
            $employee_id = (int)($_POST['employee_id'] ?? 0);
            
            if ($employee_id > 0) {
                // Check for duplicate username (excluding current ID)
                $check_stmt = $mysqli->prepare("SELECT employee_id FROM employee_master WHERE employee_username = ? AND employee_id != ?");
                $check_stmt->bind_param("si", $employee_username, $employee_id);
                $check_stmt->execute();
                $check_stmt->store_result();
                
                if ($check_stmt->num_rows > 0) {
                    $check_stmt->close();
                    redirect_to('../employee_entry.php', ['msg' => 'Username already exists.', 'type' => 'danger', 'id' => $employee_id]);
                }
                $check_stmt->close();
                
                // Update employee
                $sql = "UPDATE employee_master SET employee_name = ?, employee_username = ?, email = ?, phone = ?, joining_date = ?, designation_id = ?, user_role_id = ?, status = ? WHERE employee_id = ?";
                $stmt = $mysqli->prepare($sql);
                $stmt->bind_param("sssssiiis", $employee_name, $employee_username, $email, $phone, $joining_date, $designation_id, $user_role_id, $status, $employee_id);
                
                if ($stmt->execute()) {
                    redirect_to('../employee_master.php', ['msg' => "Employee updated successfully", 'type' => 'success']);
                } else {
                    redirect_to('../employee_entry.php', ['msg' => 'Error updating employee: ' . $stmt->error, 'type' => 'danger', 'id' => $employee_id]);
                }
                $stmt->close();
            }
        }
    }
    
    if ($action === 'delete') {
        $employee_id = (int)($_POST['employee_id'] ?? 0);
        
        if ($employee_id > 0) {
            // Get employee name for message
            $res = $mysqli->prepare("SELECT employee_name FROM employee_master WHERE employee_id = ?");
            $res->bind_param("i", $employee_id);
            $res->execute();
            $res->bind_result($employee_name);
            $res->fetch();
            $res->close();
            
            // Delete employee
            $del = $mysqli->prepare("DELETE FROM employee_master WHERE employee_id = ?");
            $del->bind_param("i", $employee_id);
            $del->execute();
            $del->close();
            
            redirect_to('../employee_master.php', ['msg' => "Employee '$employee_name' deleted", 'type' => 'warning']);
        }
    }
    
    if ($action === 'toggle') {
        $employee_id = (int)($_POST['employee_id'] ?? 0);
        
        if ($employee_id > 0) {
            $res = $mysqli->prepare("SELECT status, employee_name FROM employee_master WHERE employee_id = ?");
            $res->bind_param("i", $employee_id);
            $res->execute();
            $res->bind_result($current_status, $employee_name);
            $res->fetch();
            $res->close();
            
            if ($employee_name) {
                $newStatus = ($current_status === 'Active') ? 'Inactive' : 'Active';
                
                $upd = $mysqli->prepare("UPDATE employee_master SET status = ? WHERE employee_id = ?");
                $upd->bind_param("si", $newStatus, $employee_id);
                $upd->execute();
                $upd->close();
                
                redirect_to('../employee_master.php', ['msg' => "Employee '$employee_name' status changed to $newStatus", 'type' => 'info']);
            }
        }
    }
    
    redirect_to('../employee_master.php');
}
?>