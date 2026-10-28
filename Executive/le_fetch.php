<?php
session_start();
require 'db.php';

header('Content-Type: application/json');

// Check if it's an AJAX request
if (!isset($_SERVER['HTTP_X_REQUESTED_WITH']) || strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) != 'xmlhttprequest') {
    http_response_code(403);
    echo json_encode(['error' => 'Direct access not allowed']);
    exit;
}

$query = "SELECT 
            le.id, 
            le.job_id, 
            le.date, 
            le.client, 
            le.issue, 
            le.articles, 
            le.eq, 
            le.images, 
            le.tables_count, 
            le.process, 
            le.word_count, 
            le.ref, 
            le.tat, 
            le.status, 
            le.done_by, 
            le.le_date,        
            le.due_date,       
            le.created_at,     
            le.last_updated,   
            le.eq_check,
            le.tables_count_check, 
            le.images_check,
            le.ref_check
          FROM le, ce where le.job_id=ce.job_id and ce.status='Completed' and le.status!='Completed'
        ORDER BY le.status";

$result = $mysqli->query($query);

$data = [];
if ($result) {
    while ($row = $result->fetch_assoc()) {
        foreach ($row as $key => $value) {
            if ($value === null) {
                $row[$key] = '';
            }
        }
        $data[] = $row;
    }
    $result->free();
} else {
    // Log error for debugging
    error_log("Database query failed: " . $mysqli->error);
    echo json_encode(['error' => 'Database query failed', 'data' => []]);
    exit;
}

echo json_encode(['data' => $data]);
?>