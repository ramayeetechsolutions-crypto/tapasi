<?php
require 'db.php';
header('Content-Type: application/json');

if (isset($_GET['message_id'])) {
    $message_id = (int)$_GET['message_id'];
    
    $stmt = $mysqli->prepare("SELECT client_name, client_email, message_text, message_date, email_type, email_subject, status, email_result FROM client_messages WHERE message_id = ?");
    $stmt->bind_param("i", $message_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($row = $result->fetch_assoc()) {
        echo json_encode([
            'success' => true,
            'client_name' => $row['client_name'],
            'client_email' => $row['client_email'],
            'message_text' => $row['message_text'],
            'message_date' => date('d-m-Y H:i', strtotime($row['message_date'])),
            'email_type' => $row['email_type'],
            'email_subject' => $row['email_subject'],
            'status' => $row['status'],
            'email_result' => $row['email_result']
        ]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Message not found']);
    }
    
    $stmt->close();
} else {
    echo json_encode(['success' => false, 'message' => 'No message ID provided']);
}

$mysqli->close();
?>