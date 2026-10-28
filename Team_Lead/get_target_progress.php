<?php
require 'db.php';
header('Content-Type: application/json');

if (isset($_GET['id'])) {
    $target_id = (int)$_GET['id'];
    
    $stmt = $mysqli->prepare("SELECT target_limit, achieved_target, status FROM weekly_targets WHERE id = ?");
    $stmt->bind_param("i", $target_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($row = $result->fetch_assoc()) {
        echo json_encode([
            'success' => true,
            'target_limit' => $row['target_limit'],
            'achieved_target' => $row['achieved_target'],
            'status' => $row['status']
        ]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Target not found']);
    }
    $stmt->close();
} else {
    echo json_encode(['success' => false, 'message' => 'No ID provided']);
}