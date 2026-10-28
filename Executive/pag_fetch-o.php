<?php
// pag_fetch.php (FIXED)
session_start();
require 'db.php'; // Ensure database connection is established

header('Content-Type: application/json');

$query = "
    SELECT 
        -- Job Identification Fields
        job_id, 
        date AS date, 
        client, 
        issue,  
        articles, 
        
        -- PAG-specific fields (Counts)
        pages, 
        images AS pag_images,        
        tables_count AS pag_tables_count, 
        
        -- PAG check flags (ENSURE 0 IS RETURNED IF NULL)
        IFNULL(pages_check, 0) AS pages_check, 
        IFNULL(images_check, 0) AS images_check, 
        IFNULL(tables_count_check, 0) AS tables_count_check,
        
        -- PAG status and tracking
        status, 
        done_by, 
        pag_date
    FROM pag
    -- Filter to show only jobs that are not completed in PAG process
    WHERE status != 'Completed' OR status IS NULL
    ORDER BY job_id DESC
"; 

$result = $mysqli->query($query);

$data = [];
if ($result) {
    while ($row = $result->fetch_assoc()) {
        // Handle null values for simple display (excluding fields handled by IFNULL in query)
        foreach ($row as $key => $value) {
            if ($value === null) {
                // Set default status if the record exists but status is NULL
                if ($key === 'status') {
                    $row[$key] = 'Need to Assign';
                } else {
                    $row[$key] = '';
                }
            }
        }
        $data[] = $row;
    }
    $result->free();
}

echo json_encode(['data' => $data]);
?>