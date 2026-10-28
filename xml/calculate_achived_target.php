<?php
require 'db.php';
header('Content-Type: application/json');

if (isset($_GET['target_id'])) {
    $target_id = (int)$_GET['target_id'];
    
    // Get target details
    $stmt = $mysqli->prepare("SELECT tm_id, from_date, to_date, target_limit, status FROM weekly_targets WHERE id = ?");
    $stmt->bind_param("i", $target_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($row = $result->fetch_assoc()) {
        $tm_id = $row['tm_id'];
        $from_date = $row['from_date'];
        $to_date = $row['to_date'];
        $target_limit = $row['target_limit'];
        $status = $row['status'];
        
        // Get TM username
        $tm_stmt = $mysqli->prepare("SELECT employee_username FROM employee_master WHERE employee_id = ?");
        $tm_stmt->bind_param("i", $tm_id);
        $tm_stmt->execute();
        $tm_result = $tm_stmt->get_result();
        
        $achieved_target = 0;
        if ($tm_row = $tm_result->fetch_assoc()) {
            $tm_username = $tm_row['employee_username'];
            
            // Count jobs assigned to this TM within the date range
            $count_stmt = $mysqli->prepare("SELECT COUNT(*) as job_count 
                                          FROM jobs 
                                          WHERE (
                                              job_date BETWEEN ? AND ?
                                          ) AND (
                                              assigned_ce = ? OR 
                                              assigned_le = ? OR 
                                              assigned_pag = ? OR 
                                              assigned_qc = ? OR 
                                              assigned_xml = ?
                                          )");
            $count_stmt->bind_param("sssssss", $from_date, $to_date, 
                                  $tm_username, $tm_username, $tm_username, 
                                  $tm_username, $tm_username);
            $count_stmt->execute();
            $count_result = $count_stmt->get_result();
            
            if ($count_row = $count_result->fetch_assoc()) {
                $achieved_target = $count_row['job_count'];
            }
            $count_stmt->close();
        }
        $tm_stmt->close();
        
        echo json_encode([
            'success' => true,
            'achieved_target' => $achieved_target,
            'target_limit' => $target_limit,
            'status' => $status
        ]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Target not found']);
    }
    $stmt->close();
} else {
    echo json_encode(['success' => false, 'message' => 'No target ID provided']);
}
?>