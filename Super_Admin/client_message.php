<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
require 'db.php';
if (session_status() === PHP_SESSION_NONE) { 
    session_start(); 
}

// Include PHPMailer
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;
use PHPMailer\PHPMailer\SMTP;

require 'PHPMailer-master/src/Exception.php'; 
require 'PHPMailer-master/src/PHPMailer.php'; 
require 'PHPMailer-master/src/SMTP.php';

// Helper functions
function h($s) { 
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); 
}

function formatDate($d) {
    if (empty($d) || $d === '0000-00-00' || $d === '0000-00-00 00:00:00') return '—';
    return date('d-m-Y H:i', strtotime($d));
}

function redirect_with($params = []) {
    $base = 'client_message.php';
    if (!empty($params)) $base .= '?' . http_build_query($params);
    header("Location: $base");
    exit;
}

// Email sending function with multiple SMTP configurations
function sendEmailToClient($client_name, $client_email, $message, $subject = "Message from Company") {
    global $mysqli;
    
    $smtp_configs = [
        [
            'host' => 'mail.rtssoftware.in',
            'user' => 'tps@rtssoftware.in',
            'pass' => 'tps@2025',
            'secure' => PHPMailer::ENCRYPTION_STARTTLS,
            'port' => 587,
            'name' => 'Primary (TLS - Port 587)'
        ],
        [
            'host' => 'mail.rtssoftware.com',
            'user' => 'tps@rtssoftware.in',
            'pass' => 'tps@2025',
            'secure' => PHPMailer::ENCRYPTION_SMTPS,
            'port' => 465,
            'name' => 'Alternative (SSL - Port 465)'
        ],
        [
            'host' => 'mail.rtssoftware.com',
            'user' => 'tps@rtssoftware.in',
            'pass' => 'tps@2025',
            'secure' => false,
            'port' => 25,
            'name' => 'Fallback (No SSL - Port 25)'
        ]
    ];

    foreach ($smtp_configs as $config) {
        try {
            $mail = new PHPMailer(true);
            
            // SMTP Configuration
            $mail->isSMTP();
            $mail->Host       = $config['host'];
            $mail->SMTPAuth   = true;
            $mail->Username   = $config['user'];
            $mail->Password   = $config['pass'];
            $mail->SMTPSecure = $config['secure'];
            $mail->Port       = $config['port'];
            
            // Connection settings
            $mail->Timeout = 15;
            $mail->SMTPOptions = array(
                'ssl' => array(
                    'verify_peer' => false,
                    'verify_peer_name' => false,
                    'allow_self_signed' => true
                )
            );

            // Recipients
            $mail->setFrom($config['user'], 'TAPASI SOLUTIONS LLP');
            $mail->addAddress($client_email, $client_name);
            $mail->addReplyTo($config['user'], 'TAPASI SOLUTIONS LLP');

            // Content
            $mail->isHTML(true);
            $mail->Subject = $subject;
            $mail->Body    = "
                <html>
                <body style='font-family: Arial, sans-serif; line-height: 1.6;'>
                    <div style='max-width: 600px; margin: 0 auto; padding: 20px; border: 1px solid #e0e0e0; border-radius: 5px;'>
                        <h2 style='color: #2c3e50; border-bottom: 2px solid #3498db; padding-bottom: 10px;'>TAPASI SOLUTIONS LLP</h2>
                        <p>Dear $client_name,</p>
                        <div style='background: #f8f9fa; padding: 15px; border-left: 4px solid #3498db; margin: 15px 0;'>
                            " . nl2br(htmlspecialchars($message)) . "
                        </div>
                        <p>Best regards,<br><strong>TAPASI SOLUTIONS LLP Team</strong></p>
                        <hr style='border: none; border-top: 1px solid #e0e0e0; margin: 20px 0;'>
                        <p style='font-size: 12px; color: #666;'>
                            This is an automated message. Please do not reply to this email.
                        </p>
                    </div>
                </body>
                </html>
            ";
            
            // Plain text version
            $mail->AltBody = "Dear $client_name,\n\n$message\n\nBest regards,\nTAPASI SOLUTIONS LLP Team";

            if ($mail->send()) {
                return ['success' => true, 'message' => "Email sent successfully using {$config['name']}"];
            }

        } catch (Exception $e) {
            $last_error = "Failed with {$config['name']}: " . $e->getMessage();
            continue;
        }
    }

    // If all SMTP configurations failed, try using mail() function
    try {
        $mail = new PHPMailer(true);
        $mail->isMail();
        
        $mail->setFrom('noreply@rtssoftware.in', 'TAPASI SOLUTIONS LLP');
        $mail->addAddress($client_email, $client_name);
        
        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body    = "
            <html>
            <body>
                <h3>Dear $client_name,</h3>
                <p>$message</p>
                <br>
                <p>Best regards,<br>TAPASI SOLUTIONS LLP Team</p>
            </body>
            </html>
        ";
        $mail->AltBody = "Dear $client_name,\n\n$message\n\nBest regards,\nTAPASI SOLUTIONS LLP Team";
        
        if ($mail->send()) {
            return ['success' => true, 'message' => "Email sent successfully using PHP mail() function"];
        }
    } catch (Exception $e) {
        return ['success' => false, 'message' => "All email methods failed. Last error: " . $e->getMessage()];
    }

    return ['success' => false, 'message' => "All email delivery methods failed"];
}

// Get all clients for dropdown
$clients_result = $mysqli->query("SELECT `client_id`, `client_name`, `company_name`, `email`, `general_email`, `invoice_email`, `production_email`, `status` FROM `client_master` WHERE `status` = 'Active' ORDER BY `client_name`");
$clients = [];
while($row = $clients_result->fetch_assoc()) {
    $clients[$row['client_id']] = $row;
}

// Handle POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'send_message') {
        $client_id = trim($_POST['client_id'] ?? '');
        $message_text = trim($_POST['message_text'] ?? '');
        $message_date = $_POST['message_date'] ?? date('Y-m-d H:i:s');
        $email_type = $_POST['email_type'] ?? 'general';
        $email_subject = trim($_POST['email_subject'] ?? 'Important Message from TAPASI SOLUTIONS LLP');
        
        // Basic validation
        $errors = [];
        if (empty($client_id)) $errors[] = 'Client selection is required';
        if (empty($message_text)) $errors[] = 'Message text is required';
        if (strlen($message_text) > 500) $errors[] = 'Message must be 500 characters or less';
        if (empty($email_subject)) $errors[] = 'Email subject is required';
        
        if (!empty($errors)) {
            $_SESSION['form_data'] = [
                'client_id' => $client_id,
                'message_text' => $message_text,
                'message_date' => $message_date,
                'email_type' => $email_type,
                'email_subject' => $email_subject
            ];
            redirect_with(['msg' => implode(', ', $errors), 'type' => 'danger']);
        }
        
        // Get client details
        $client_name = 'All Clients';
        $client_email = '';
        $sent_emails = [];
        
        if ($client_id !== 'all') {
            // Single client
            $client = $clients[$client_id] ?? null;
            if ($client) {
                $client_name = $client['client_name'];
                switch($email_type) {
                    case 'invoice':
                        $client_email = $client['invoice_email'] ?: $client['email'];
                        break;
                    case 'production':
                        $client_email = $client['production_email'] ?: $client['email'];
                        break;
                    case 'general':
                    default:
                        $client_email = $client['general_email'] ?: $client['email'];
                        break;
                }
                
                if (empty($client_email)) {
                    $errors[] = "No email address found for client $client_name";
                }
            } else {
                $errors[] = 'Client not found';
            }
        } else {
            // All clients
            foreach ($clients as $client) {
                $email = '';
                switch($email_type) {
                    case 'invoice':
                        $email = $client['invoice_email'] ?: $client['email'];
                        break;
                    case 'production':
                        $email = $client['production_email'] ?: $client['email'];
                        break;
                    case 'general':
                    default:
                        $email = $client['general_email'] ?: $client['email'];
                        break;
                }
                
                if (!empty($email) && filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    $sent_emails[] = [
                        'name' => $client['client_name'],
                        'email' => $email
                    ];
                }
            }
            
            if (empty($sent_emails)) {
                $errors[] = 'No valid email addresses found for any active clients';
            }
        }
        
        if (!empty($errors)) {
            redirect_with(['msg' => implode(', ', $errors), 'type' => 'danger']);
        }
        
        // Insert message into database and send emails
        $success_count = 0;
        $fail_count = 0;
        $email_results = [];
        
        if ($client_id !== 'all') {
            // Single client
            $stmt = $mysqli->prepare("INSERT INTO client_messages (client_id, client_name, client_email, message_text, message_date, email_type, email_subject, status) VALUES (?, ?, ?, ?, ?, ?, ?, 'Sent')");
            if ($stmt) {
                $stmt->bind_param("sssssss", $client_id, $client_name, $client_email, $message_text, $message_date, $email_type, $email_subject);
                
                if ($stmt->execute()) {
                    $message_id = $stmt->insert_id;
                    
                    // Send email
                    $email_result = sendEmailToClient($client_name, $client_email, $message_text, $email_subject);
                    
                    if ($email_result['success']) {
                        $status = 'Delivered';
                        $success_count = 1;
                        $email_results[] = "✓ $client_name: " . $email_result['message'];
                    } else {
                        $status = 'Failed';
                        $fail_count = 1;
                        $email_results[] = "✗ $client_name: " . $email_result['message'];
                    }
                    
                    // Update status
                    $update_stmt = $mysqli->prepare("UPDATE client_messages SET status = ?, sent_date = NOW(), email_result = ? WHERE message_id = ?");
                    $email_result_text = implode("\n", $email_results);
                    $update_stmt->bind_param("ssi", $status, $email_result_text, $message_id);
                    $update_stmt->execute();
                    $update_stmt->close();
                    
                    $stmt->close();
                }
            }
        } else {
            // Multiple clients - use transaction
            $mysqli->begin_transaction();
            
            try {
                foreach ($sent_emails as $client_data) {
                    $stmt = $mysqli->prepare("INSERT INTO client_messages (client_id, client_name, client_email, message_text, message_date, email_type, email_subject, status) VALUES ('all', ?, ?, ?, ?, ?, ?, 'Sent')");
                    $stmt->bind_param("ssssss", $client_data['name'], $client_data['email'], $message_text, $message_date, $email_type, $email_subject);
                    $stmt->execute();
                    $message_id = $stmt->insert_id;
                    $stmt->close();
                    
                    // Send email
                    $email_result = sendEmailToClient($client_data['name'], $client_data['email'], $message_text, $email_subject);
                    
                    if ($email_result['success']) {
                        $status = 'Delivered';
                        $success_count++;
                        $email_results[] = "✓ {$client_data['name']}: Success";
                    } else {
                        $status = 'Failed';
                        $fail_count++;
                        $email_results[] = "✗ {$client_data['name']}: Failed - " . $email_result['message'];
                    }
                    
                    // Update status
                    $update_stmt = $mysqli->prepare("UPDATE client_messages SET status = ?, sent_date = NOW(), email_result = ? WHERE message_id = ?");
                    $update_stmt->bind_param("ssi", $status, $email_result['message'], $message_id);
                    $update_stmt->execute();
                    $update_stmt->close();
                }
                
                $mysqli->commit();
            } catch (Exception $e) {
                $mysqli->rollback();
                redirect_with(['msg' => 'Database error: ' . $e->getMessage(), 'type' => 'danger']);
            }
        }
        
        unset($_SESSION['form_data']);
        
        // Prepare result message
        $result_msg = "Message processing completed. ";
        if ($success_count > 0) {
            $result_msg .= "Successfully sent to $success_count client(s). ";
        }
        if ($fail_count > 0) {
            $result_msg .= "Failed to send to $fail_count client(s).";
        }
        
        // Store detailed results in session for viewing
        $_SESSION['email_results'] = [
            'success_count' => $success_count,
            'fail_count' => $fail_count,
            'details' => $email_results,
            'subject' => $email_subject,
            'message' => $message_text
        ];
        
        redirect_with(['msg' => $result_msg, 'type' => $fail_count === 0 ? 'success' : 'warning']);
    }
    
    if ($action === 'delete_message') {
        $message_id = (int)($_POST['message_id'] ?? 0);
        
        if ($message_id > 0) {
            $res = $mysqli->prepare("SELECT client_name, message_text FROM client_messages WHERE message_id = ?");
            if ($res) {
                $res->bind_param("i", $message_id);
                $res->execute();
                $res->bind_result($client_name, $message_text);
                $res->fetch();
                $res->close();
                
                $del = $mysqli->prepare("DELETE FROM client_messages WHERE message_id = ?");
                if ($del) {
                    $del->bind_param("i", $message_id);
                    $del->execute();
                    $del->close();
                    
                    redirect_with(['msg' => "Message to $client_name deleted successfully", 'type' => 'warning']);
                }
            }
        }
    }
    
    if ($action === 'resend_message') {
        $message_id = (int)($_POST['message_id'] ?? 0);
        
        if ($message_id > 0) {
            $res = $mysqli->prepare("SELECT client_name, client_email, message_text, email_subject FROM client_messages WHERE message_id = ?");
            if ($res) {
                $res->bind_param("i", $message_id);
                $res->execute();
                $res->bind_result($client_name, $client_email, $message_text, $email_subject);
                $res->fetch();
                $res->close();
                
                if ($client_name) {
                    // Resend email
                    $email_result = sendEmailToClient($client_name, $client_email, $message_text, $email_subject);
                    
                    // Update status
                    $new_status = $email_result['success'] ? 'Delivered' : 'Failed';
                    $upd = $mysqli->prepare("UPDATE client_messages SET status = ?, sent_date = NOW(), email_result = ? WHERE message_id = ?");
                    if ($upd) {
                        $upd->bind_param("ssi", $new_status, $email_result['message'], $message_id);
                        $upd->execute();
                        $upd->close();
                        
                        $status_msg = $email_result['success'] ? "Message re-sent successfully to $client_name" : "Failed to re-send message to $client_name: " . $email_result['message'];
                        redirect_with(['msg' => $status_msg, 'type' => $email_result['success'] ? 'success' : 'danger']);
                    }
                }
            }
        }
    }
    
    redirect_with();
}

// View email results
if (isset($_GET['view_results'])) {
    $email_results = $_SESSION['email_results'] ?? null;
    if ($email_results) {
        unset($_SESSION['email_results']);
    }
}

// Get search and filter parameters
$q = trim($_GET['q'] ?? '');
$fclient = $_GET['client'] ?? '';
$fstatus = $_GET['status'] ?? '';
$fdate = $_GET['date'] ?? '';
$femail_type = $_GET['email_type'] ?? '';
$sort = $_GET['sort'] ?? 'message_date';
$dir = strtolower($_GET['dir'] ?? 'desc');

// Build query for client_messages table
$sql = "SELECT message_id, client_id, client_name, client_email, message_text, message_date, email_type, email_subject, status, sent_date, email_result FROM client_messages";
$cond = [];
$params = [];
$types = '';

if ($q !== '') {
    $cond[] = "(client_name LIKE ? OR message_text LIKE ? OR client_email LIKE ? OR email_subject LIKE ?)";
    $like = "%$q%";
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
    $types .= 'ssss';
}

if ($fclient !== '') {
    if ($fclient === 'all') {
        $cond[] = "client_id = 'all'";
    } else {
        $cond[] = "client_id = ?";
        $params[] = $fclient;
        $types .= 's';
    }
}

if ($fstatus !== '') {
    $cond[] = "status = ?";
    $params[] = $fstatus;
    $types .= 's';
}

if ($fdate !== '') {
    $cond[] = "DATE(message_date) = ?";
    $params[] = $fdate;
    $types .= 's';
}

if ($femail_type !== '') {
    $cond[] = "email_type = ?";
    $params[] = $femail_type;
    $types .= 's';
}

if (!empty($cond)) {
    $sql .= " WHERE " . implode(" AND ", $cond);
}

$sql .= " ORDER BY $sort $dir";

// Execute query
if (!empty($types)) {
    $stmt = $mysqli->prepare($sql);
    if ($stmt) {
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $messages = $stmt->get_result();
    } else {
        $messages = false;
    }
} else {
    $messages = $mysqli->query($sql);
}

if (!$messages) {
    $messages = false;
}

// Get form data from session if exists
$formData = $_SESSION['form_data'] ?? [];
unset($_SESSION['form_data']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Client Messaging System</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body {
            background-color: #f8f9fa;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }
        .main-content {
            padding: 20px;
            max-width: 1600px;
            margin: 0 auto;
        }
        .table th {
            background-color: #2c3e50;
            color: white;
            border: none;
        }
        .table td {
            vertical-align: middle;
        }
        .card {
            box-shadow: 0 0.125rem 0.25rem rgba(0, 0, 0, 0.075);
            border: 1px solid rgba(0, 0, 0, 0.125);
        }
        .btn-group-sm > .btn {
            padding: 0.25rem 0.5rem;
        }
        .message-cell {
            max-width: 300px;
            word-wrap: break-word;
        }
        .status-badge {
            font-size: 0.75em;
        }
        .client-badge {
            font-size: 0.8em;
        }
        .char-count {
            font-size: 0.875em;
        }
        .email-type-badge {
            font-size: 0.7em;
        }
        .email-results {
            max-height: 300px;
            overflow-y: auto;
        }
    </style>
</head>
<body>
    <?php if (file_exists('header.php')) require 'header.php'; ?>
    
    <div class="main-content">
        <?php if (!empty($_GET['msg'])): ?>
        <div class="alert alert-<?= h($_GET['type'] ?? 'info') ?> alert-dismissible fade show" role="alert">
            <?= h($_GET['msg']) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <?php endif; ?>

        <!-- Email Results Modal -->
        <?php if (isset($email_results)): ?>
        <div class="modal fade show" style="display: block; background: rgba(0,0,0,0.5);" tabindex="-1">
            <div class="modal-dialog modal-lg">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title"><i class="fas fa-envelope me-2"></i>Email Delivery Results</h5>
                        <button type="button" class="btn-close" onclick="window.location.href='client_message.php'"></button>
                    </div>
                    <div class="modal-body">
                        <div class="row mb-3">
                            <div class="col-md-4">
                                <div class="card text-white bg-success">
                                    <div class="card-body text-center">
                                        <h4><?= $email_results['success_count'] ?></h4>
                                        <small>Successful</small>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="card text-white bg-danger">
                                    <div class="card-body text-center">
                                        <h4><?= $email_results['fail_count'] ?></h4>
                                        <small>Failed</small>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="card text-white bg-info">
                                    <div class="card-body text-center">
                                        <h4><?= $email_results['success_count'] + $email_results['fail_count'] ?></h4>
                                        <small>Total</small>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <h6>Subject: <?= h($email_results['subject']) ?></h6>
                        <p class="text-muted"><?= nl2br(h($email_results['message'])) ?></p>
                        
                        <div class="email-results border rounded p-3">
                            <?php foreach($email_results['details'] as $result): ?>
                                <div class="mb-2"><?= nl2br(h($result)) ?></div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" onclick="window.location.href='client_message.php'">Close</button>
                    </div>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- Header -->
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h4><i class="fas fa-envelope me-2"></i>Client Messaging System</h4>
            <button class="btn btn-success" data-bs-toggle="modal" data-bs-target="#messageModal">
                <i class="fas fa-plus me-1"></i> New Message
            </button>
        </div>

        <!-- Search and Filter Form -->
        <div class="card mb-4">
            <div class="card-body">
                <form method="get" class="row g-3 align-items-center">
                    <div class="col-md-3">
                        <input type="text" name="q" class="form-control" 
                               placeholder="Search clients or messages..." value="<?= h($q) ?>">
                    </div>
                    <div class="col-md-2">
                        <select name="client" class="form-select">
                            <option value="">All Clients</option>
                            <option value="all" <?= $fclient === 'all' ? 'selected' : '' ?>>All Clients</option>
                            <?php foreach($clients as $client_id => $client): ?>
                                <option value="<?= h($client_id) ?>" <?= $fclient === $client_id ? 'selected' : '' ?>>
                                    <?= h($client['client_name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <input type="date" name="date" class="form-control" value="<?= h($fdate) ?>">
                    </div>
                    <div class="col-md-2">
                        <select name="status" class="form-select">
                            <option value="">All Status</option>
                            <option value="Sent" <?= $fstatus === 'Sent' ? 'selected' : '' ?>>Sent</option>
                            <option value="Delivered" <?= $fstatus === 'Delivered' ? 'selected' : '' ?>>Delivered</option>
                            <option value="Failed" <?= $fstatus === 'Failed' ? 'selected' : '' ?>>Failed</option>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <select name="email_type" class="form-select">
                            <option value="">All Types</option>
                            <option value="general" <?= $femail_type === 'general' ? 'selected' : '' ?>>General</option>
                            <option value="invoice" <?= $femail_type === 'invoice' ? 'selected' : '' ?>>Invoice</option>
                            <option value="production" <?= $femail_type === 'production' ? 'selected' : '' ?>>Production</option>
                        </select>
                    </div>
                    <div class="col-md-1">
                        <select name="sort" class="form-select">
                            <option value="message_date" <?= $sort === 'message_date' ? 'selected' : '' ?>>Date</option>
                            <option value="client_name" <?= $sort === 'client_name' ? 'selected' : '' ?>>Client</option>
                        </select>
                    </div>
                    <div class="col-md-12 mt-2">
                        <button type="submit" class="btn btn-primary me-2">
                            <i class="fas fa-search me-1"></i> Search
                        </button>
                        <a href="client_message.php" class="btn btn-secondary">
                            <i class="fas fa-times me-1"></i> Clear
                        </a>
                    </div>
                </form>
            </div>
        </div>

        <!-- Messages Table -->
        <div class="card">
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th width="60">S.No</th>
                                <th width="150">Client</th>
                                <th>Subject & Message</th>
                                <th width="120">Email</th>
                                <th width="150">Date & Time</th>
                                <th width="100">Status</th>
                                <th width="180" class="text-center">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if($messages && $messages->num_rows > 0): ?>
                                <?php while($row = $messages->fetch_assoc()): ?>
                                <tr>
                                    <td class="fw-bold"><?= (int)$row['message_id'] ?></td>
                                    <td>
                                        <span class="badge <?= getClientBadgeClass($row['client_name']) ?> client-badge">
                                            <?= h($row['client_name']) ?>
                                        </span>
                                        <br>
                                        <small class="badge bg-secondary email-type-badge">
                                            <?= h(ucfirst($row['email_type'])) ?>
                                        </small>
                                    </td>
                                    <td class="message-cell">
                                        <div class="fw-semibold text-primary"><?= h($row['email_subject']) ?></div>
                                        <div class="text-muted small"><?= h(truncateMessage($row['message_text'], 60)) ?></div>
                                    </td>
                                    <td>
                                        <small class="text-muted"><?= h(truncateMessage($row['client_email'], 20)) ?></small>
                                    </td>
                                    <td>
                                        <small class="text-muted"><?= h(formatDate($row['message_date'])) ?></small>
                                        <?php if ($row['sent_date'] && $row['sent_date'] !== '0000-00-00 00:00:00'): ?>
                                            <br><small class="text-info">Sent: <?= h(formatDate($row['sent_date'])) ?></small>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <span class="badge <?= getStatusBadgeClass($row['status']) ?> status-badge">
                                            <?= h($row['status']) ?>
                                        </span>
                                    </td>
                                    <td class="text-center">
                                        <div class="btn-group btn-group-sm" role="group">
                                            <button class="btn btn-info" onclick="viewMessage(<?= (int)$row['message_id'] ?>)" 
                                                    data-bs-toggle="modal" data-bs-target="#viewMessageModal" title="View Message">
                                                <i class="fas fa-eye"></i>
                                            </button>
                                            <?php if ($row['status'] === 'Failed'): ?>
                                            <form method="post" style="display:inline;">
                                                <input type="hidden" name="action" value="resend_message">
                                                <input type="hidden" name="message_id" value="<?= (int)$row['message_id'] ?>">
                                                <button type="submit" class="btn btn-warning" title="Resend Message">
                                                    <i class="fas fa-redo"></i>
                                                </button>
                                            </form>
                                            <?php endif; ?>
                                            <form method="post" style="display:inline;" 
                                                  onsubmit="return confirm('Are you sure you want to delete this message?')">
                                                <input type="hidden" name="action" value="delete_message">
                                                <input type="hidden" name="message_id" value="<?= (int)$row['message_id'] ?>">
                                                <button type="submit" class="btn btn-danger" title="Delete Message">
                                                    <i class="fas fa-trash"></i>
                                                </button>
                                            </form>
                                        </div>
                                    </td>
                                </tr>
                                <?php endwhile; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="7" class="text-center py-5">
                                        <div class="text-muted">
                                            <i class="fas fa-envelope fa-3x mb-3"></i>
                                            <h5>No messages found</h5>
                                            <p class="mb-0">Get started by sending your first message to a client.</p>
                                            <button class="btn btn-primary mt-2" data-bs-toggle="modal" data-bs-target="#messageModal">
                                                <i class="fas fa-plus me-1"></i> New Message
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- Summary Card -->
        <?php if($messages && $messages->num_rows > 0): ?>
        <?php
            $messages->data_seek(0);
            $total_messages = 0;
            $sent_messages = 0;
            $delivered_messages = 0;
            $failed_messages = 0;
            $client_counts = [];
            
            while($row = $messages->fetch_assoc()) {
                $total_messages++;
                if ($row['status'] === 'Sent') $sent_messages++;
                if ($row['status'] === 'Delivered') $delivered_messages++;
                if ($row['status'] === 'Failed') $failed_messages++;
                $client_counts[$row['client_name']] = ($client_counts[$row['client_name']] ?? 0) + 1;
            }
            $unique_clients = count($client_counts);
        ?>
        <div class="card mt-4">
            <div class="card-body">
                <div class="row text-center">
                    <div class="col-md-3">
                        <h5 class="text-primary"><?= $total_messages ?></h5>
                        <small class="text-muted">Total Messages</small>
                    </div>
                    <div class="col-md-2">
                        <h5 class="text-info"><?= $sent_messages ?></h5>
                        <small class="text-muted">Sent</small>
                    </div>
                    <div class="col-md-2">
                        <h5 class="text-success"><?= $delivered_messages ?></h5>
                        <small class="text-muted">Delivered</small>
                    </div>
                    <div class="col-md-2">
                        <h5 class="text-danger"><?= $failed_messages ?></h5>
                        <small class="text-muted">Failed</small>
                    </div>
                    <div class="col-md-3">
                        <h5 class="text-warning"><?= $unique_clients ?></h5>
                        <small class="text-muted">Unique Clients</small>
                    </div>
                </div>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <!-- Message Modal -->
    <div class="modal fade" id="messageModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-paper-plane me-2"></i>Send New Message to Client</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="post" id="messageForm">
                    <div class="modal-body">
                        <input type="hidden" name="action" value="send_message">
                        <div class="mb-3">
                            <label for="clientSelect" class="form-label">Select Client <span class="text-danger">*</span></label>
                            <select class="form-select" id="clientSelect" name="client_id" required>
                                <option value="">Choose Client...</option>
                                <option value="all" <?= ($formData['client_id'] ?? '') === 'all' ? 'selected' : '' ?>>All Clients</option>
                                <?php foreach($clients as $client_id => $client): ?>
                                    <option value="<?= h($client_id) ?>" 
                                        <?= ($formData['client_id'] ?? '') === $client_id ? 'selected' : '' ?>
                                        data-email="<?= h($client['email']) ?>"
                                        data-general-email="<?= h($client['general_email']) ?>"
                                        data-invoice-email="<?= h($client['invoice_email']) ?>"
                                        data-production-email="<?= h($client['production_email']) ?>">
                                        <?= h($client['client_name']) ?> (<?= h($client['company_name']) ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label for="emailSubject" class="form-label">Email Subject <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" id="emailSubject" name="email_subject" 
                                   value="<?= h($formData['email_subject'] ?? 'Important Message from TAPASI SOLUTIONS LLP') ?>" required>
                        </div>
                        <div class="mb-3">
                            <label for="emailType" class="form-label">Email Type</label>
                            <select class="form-select" id="emailType" name="email_type">
                                <option value="general" <?= ($formData['email_type'] ?? '') === 'general' ? 'selected' : '' ?>>General Email</option>
                                <option value="invoice" <?= ($formData['email_type'] ?? '') === 'invoice' ? 'selected' : '' ?>>Invoice Email</option>
                                <option value="production" <?= ($formData['email_type'] ?? '') === 'production' ? 'selected' : '' ?>>Production Email</option>
                            </select>
                            <div class="form-text" id="emailPreview"></div>
                        </div>
                        <div class="mb-3">
                            <label for="messageText" class="form-label">Message <span class="text-danger">*</span></label>
                            <textarea class="form-control" id="messageText" name="message_text" rows="5" 
                                      placeholder="Enter your message here..." required maxlength="500"><?= h($formData['message_text'] ?? '') ?></textarea>
                            <div class="form-text char-count">
                                <span id="charCount">0</span>/500 characters
                            </div>
                        </div>
                        <div class="mb-3">
                            <label for="messageDate" class="form-label">Schedule Date & Time</label>
                            <input type="datetime-local" class="form-control" id="messageDate" name="message_date" 
                                   value="<?= h($formData['message_date'] ?? date('Y-m-d\TH:i')) ?>">
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-paper-plane me-1"></i> Send Message
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- View Message Modal -->
    <div class="modal fade" id="viewMessageModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-eye me-2"></i>Message Details</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body" id="viewMessageContent">
                    <!-- Content will be loaded via JavaScript -->
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // Auto-dismiss alerts after 5 seconds
            const alerts = document.querySelectorAll('.alert');
            alerts.forEach(alert => {
                setTimeout(() => {
                    if (alert.isConnected) {
                        const bsAlert = new bootstrap.Alert(alert);
                        bsAlert.close();
                    }
                }, 5000);
            });

            // Message character count
            const messageText = document.getElementById('messageText');
            const charCount = document.getElementById('charCount');
            
            if (messageText && charCount) {
                charCount.textContent = messageText.value.length;
                messageText.addEventListener('input', function() {
                    charCount.textContent = this.value.length;
                });
            }

            // Email type and client selection handling
            const clientSelect = document.getElementById('clientSelect');
            const emailType = document.getElementById('emailType');
            const emailPreview = document.getElementById('emailPreview');

            function updateEmailPreview() {
                const selectedClient = clientSelect.options[clientSelect.selectedIndex];
                const emailTypeValue = emailType.value;
                
                if (selectedClient && selectedClient.value !== '' && selectedClient.value !== 'all') {
                    let email = '';
                    switch(emailTypeValue) {
                        case 'invoice':
                            email = selectedClient.dataset.invoiceEmail || selectedClient.dataset.email;
                            break;
                        case 'production':
                            email = selectedClient.dataset.productionEmail || selectedClient.dataset.email;
                            break;
                        case 'general':
                        default:
                            email = selectedClient.dataset.generalEmail || selectedClient.dataset.email;
                            break;
                    }
                    
                    if (email) {
                        emailPreview.textContent = `Will send to: ${email}`;
                        emailPreview.className = 'form-text text-success';
                    } else {
                        emailPreview.textContent = 'No email available for this client';
                        emailPreview.className = 'form-text text-danger';
                    }
                } else if (selectedClient.value === 'all') {
                    emailPreview.textContent = 'Will send to all active clients';
                    emailPreview.className = 'form-text text-info';
                } else {
                    emailPreview.textContent = 'Select a client to see email address';
                    emailPreview.className = 'form-text text-muted';
                }
            }

            if (clientSelect && emailType && emailPreview) {
                clientSelect.addEventListener('change', updateEmailPreview);
                emailType.addEventListener('change', updateEmailPreview);
                updateEmailPreview();
            }
        });

        function viewMessage(messageId) {
            fetch('get_message_details.php?message_id=' + messageId)
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        const content = document.getElementById('viewMessageContent');
                        content.innerHTML = `
                            <div class="row">
                                <div class="col-md-6">
                                    <strong>Client:</strong> ${data.client_name}
                                </div>
                                <div class="col-md-6">
                                    <strong>Email:</strong> ${data.client_email}
                                </div>
                            </div>
                            <div class="row mt-2">
                                <div class="col-md-6">
                                    <strong>Subject:</strong> ${data.email_subject}
                                </div>
                                <div class="col-md-6">
                                    <strong>Type:</strong> <span class="badge bg-secondary">${data.email_type}</span>
                                </div>
                            </div>
                            <div class="row mt-2">
                                <div class="col-md-6">
                                    <strong>Sent Date:</strong> ${data.message_date}
                                </div>
                                <div class="col-md-6">
                                    <strong>Status:</strong> <span class="badge ${getStatusBadgeClass(data.status)}">${data.status}</span>
                                </div>
                            </div>
                            <div class="mt-3">
                                <strong>Message:</strong>
                                <div class="border rounded p-3 mt-2 bg-light">
                                    ${data.message_text.replace(/\n/g, '<br>')}
                                </div>
                            </div>
                            ${data.email_result ? `
                            <div class="mt-3">
                                <strong>Delivery Result:</strong>
                                <div class="border rounded p-3 mt-2 bg-light">
                                    ${data.email_result.replace(/\n/g, '<br>')}
                                </div>
                            </div>
                            ` : ''}
                        `;
                    } else {
                        document.getElementById('viewMessageContent').innerHTML = '<div class="alert alert-danger">Error loading message details</div>';
                    }
                })
                .catch(error => {
                    document.getElementById('viewMessageContent').innerHTML = '<div class="alert alert-danger">Error loading message details</div>';
                });
        }

        function getClientBadgeClass(clientName) {
            const colors = ['bg-success', 'bg-info', 'bg-warning', 'bg-danger', 'bg-secondary', 'bg-dark'];
            const index = Math.abs(clientName.split('').reduce((a, b) => {
                a = ((a << 5) - a) + b.charCodeAt(0);
                return a & a;
            }, 0)) % colors.length;
            return colors[index];
        }

        function getStatusBadgeClass(status) {
            const statusClasses = {
                'Sent': 'bg-info',
                'Delivered': 'bg-success',
                'Failed': 'bg-danger'
            };
            return statusClasses[status] || 'bg-primary';
        }

        function truncateMessage(message, length = 80) {
            return message.length > length ? message.substring(0, length) + '...' : message;
        }
    </script>
</body>
</html>

<?php
// PHP helper functions
function getClientBadgeClass($clientName) {
    $colors = ['bg-success', 'bg-info', 'bg-warning', 'bg-danger', 'bg-secondary', 'bg-dark'];
    $index = abs(crc32($clientName)) % count($colors);
    return $colors[$index];
}

function getStatusBadgeClass($status) {
    $statusClasses = [
        'Sent' => 'bg-info',
        'Delivered' => 'bg-success', 
        'Failed' => 'bg-danger'
    ];
    return $statusClasses[$status] ?? 'bg-primary';
}

function truncateMessage($message, $length = 80) {
    return strlen($message) > $length ? substr($message, 0, $length) . '...' : $message;
}
?>