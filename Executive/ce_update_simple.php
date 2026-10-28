<?php
session_start();
header('Content-Type: application/json');

$response = ['success' => false, 'error' => ''];

if (!isset($_POST['id'])) {
    $response['error'] = 'Missing ID. POST data: ' . print_r($_POST, true);
    echo json_encode($response);
    exit;
}

// Simulate success
$response['success'] = true;
$response['new_data'] = 'Test User / In Progress';
$response['done_by'] = 'Test User';
$response['ce_date'] = date('Y-m-d H:i:s');
$response['status_updated'] = true;

echo json_encode($response);
?>