<?php
require 'db.php';
session_start();

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['check_duplicate'])) {
    $date = $_POST['date'] ?? '';
    $employee_id = $_POST['employee_id'] ?? '';
    $exclude_id = (int)($_POST['count_id'] ?? 0);
    
    $response = ['duplicate' => false];
    
    if ($date && $employee_id) {
        $stmt = $mysqli->prepare("SELECT COUNT(*) as count FROM wordcount_master WHERE date = ? AND employee_id = ? AND count_id != ?");
        if ($stmt) {
            $stmt->bind_param("ssi", $date, $employee_id, $exclude_id);
            $stmt->execute();
            $result = $stmt->get_result();
            $row = $result->fetch_assoc();
            $stmt->close();
            
            $response['duplicate'] = $row['count'] > 0;
        }
    }
    
    echo json_encode($response);
    exit;
}