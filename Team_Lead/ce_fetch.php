<?php
session_start();
require 'db.php'; 

header('Content-Type: application/json');

// Fetch CE records
$query = "SELECT 
            id, 
            job_id, 
            date, 
            client, 
            issue, 
            articles, 
            eq, 
            images, 
            tables_count, 
            process, 
            word_count, 
            ref, 
            tat, 
            status, 
            done_by, 
            ce_date,
            eq_check,
            images_check,
            tables_count_check,
            ref_check
          FROM ce 
          ORDER BY id DESC";

$result = $mysqli->query($query);

$data = [];
if ($result) {
    while ($row = $result->fetch_assoc()) {
        // Format data for display
        foreach ($row as $key => $value) {
            if ($value === null) {
                $row[$key] = '';
            }
        }
        $data[] = $row;
    }
    $result->free();
}

echo json_encode(['data' => $data]);
?>