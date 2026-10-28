<?php
// Enable debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once 'db.php';
require_once 'header.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;
use PHPMailer\PHPMailer\SMTP;

require 'PHPMailer-master/src/Exception.php'; 
require 'PHPMailer-master/src/PHPMailer.php'; 
require 'PHPMailer-master/src/SMTP.php';

// ===============================================
// CONFIGURATION
// ===============================================
$base_upload_dir = __DIR__ . '/invoices/';
$relative_file_path = isset($_GET['file']) ? trim($_GET['file']) : '';
$client_folder_name = isset($_GET['client']) ? basename(trim($_GET['client'])) : '';

// Validate file
$full_file_path = $base_upload_dir . $relative_file_path;
$file_name = basename($full_file_path);

if (empty($relative_file_path) || !is_file($full_file_path)) {
    die("Error: File '$relative_file_path' does not exist at: $full_file_path");
}

// ===============================================
// GET CLIENT EMAIL
// ===============================================
$client_email = null;
$name_parts = explode('_', $client_folder_name);
$clean_client_name = $name_parts[0];

if (empty($clean_client_name)) {
    die("Error: Could not extract client name from: '$client_folder_name'");
}

$client_query = $mysqli->prepare("SELECT `email`, `invoice_email`, `general_email` FROM `client_master` WHERE `client_name` = ? LIMIT 1");
$client_query->bind_param("s", $clean_client_name);
$client_query->execute();
$client_result = $client_query->get_result();

if ($client_result && $client_result->num_rows > 0) {
    $row = $client_result->fetch_assoc();
    $client_email = $row['email'];
}
$client_query->close();

if (empty($client_email) || !filter_var($client_email, FILTER_VALIDATE_EMAIL)) {
    $mysqli->close();
    die("Error: No valid email for client '$clean_client_name'. Found: '$client_email'");
}
$mysqli->close();

// ===============================================
// EMAIL SENDING WITH MULTIPLE SMTP OPTIONS
// ===============================================

$smtp_configs = [
    // Try different configurations
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

$result_message = '';
$result_class = 'error';

foreach ($smtp_configs as $config) {
    try {
      //  echo "<div class='debug-info'>Trying SMTP Configuration: <strong>{$config['name']}</strong></div>";
        
        $mail = new PHPMailer(true);
        
        // Less verbose debugging
        $mail->SMTPDebug = SMTP::DEBUG_SERVER;
        $mail->Debugoutput = function($str, $level) {
       //     echo "<div style='background:#f0f0f0; padding:5px; margin:2px; font-size:12px;'>SMTP: " . htmlspecialchars($str) . "</div>";
        };

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
        $mail->addAddress($client_email, $clean_client_name);
        $mail->addReplyTo($config['user'], 'TAPASI SOLUTIONS LLP');

        // Content
        $mail->isHTML(false);
        $mail->Subject = "Your Invoice: $file_name";
        $mail->Body    = "Dear $clean_client_name,\n\nPlease find your requested invoice attached: $file_name.\n\nThank you.\n\nTAPASI SOLUTIONS LLP";

        // Attachment
        if (!$mail->addAttachment($full_file_path, $file_name)) {
            throw new Exception("Failed to add attachment");
        }

        if ($mail->send()) {
            $result_message = "✅ Success! The invoice '$file_name' was sent to $client_email using {$config['name']}.";
            $result_class = "success";
            break; // Stop trying if successful
        }

    } catch (Exception $e) {
        $last_error = "❌ Failed with {$config['name']}: " . $e->getMessage();
        if (isset($mail)) {
            $last_error .= " | PHPMailer: " . $mail->ErrorInfo;
        }
        $result_message = $last_error;
        continue; // Try next configuration
    }
}

// If all configurations failed, try using mail() function as last resort
if ($result_class === 'error') {
    try {
      //  echo "<div class='debug-info'>Trying fallback: PHP mail() function</div>";
        
        $mail = new PHPMailer(true);
        $mail->isMail(); // Use PHP's mail() function
        
        $mail->setFrom('noreply@rtssoftware.in', 'TAPASI SOLUTIONS LLP');
        $mail->addAddress($client_email, $clean_client_name);
        
        $mail->isHTML(false);
        $mail->Subject = "Your Invoice: $file_name";
        $mail->Body    = "Dear $clean_client_name,\n\nPlease find your requested invoice attached: $file_name.\n\nThank you.\n\nTAPASI SOLUTIONS LLP";
        
        $mail->addAttachment($full_file_path, $file_name);
        
        if ($mail->send()) {
            $result_message = "✅ Success! The invoice '$file_name' was sent to $client_email using PHP mail() function.";
            $result_class = "success";
        }
    } catch (Exception $e) {
        $result_message .= "\n\n❌ Fallback also failed: " . $e->getMessage();
    }
}
?>

<!DOCTYPE html>
<html>
<head>
    <title>Email Results</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 20px; }
        .success { color: green; background: #d4edda; padding: 15px; border-radius: 5px; }
        .error { color: red; background: #f8d7da; padding: 15px; border-radius: 5px; white-space: pre-wrap; }
        .debug-info { background: #fff3cd; padding: 10px; margin: 10px 0; border-radius: 5px; }
    </style>
</head>
<body>
    <h1>Invoice Emailer Results</h1>
    
    <div class="<?php echo $result_class; ?>">
        <?php echo nl2br(htmlspecialchars($result_message)); ?>
    </div>
    
  <div class="back-link-container">
    <a href="client_invoices.php" class="back-button">
        ← Back to Client Invoice
    </a>
</div>

<style>
/* CSS to be placed in your main stylesheet or <style> block */

.back-link-container {
    /* Centers the content within this container */
    text-align: center;
    margin: 20px 0; /* Add vertical spacing */
}

.back-button {
    /* Button-like styling for a professional look */
    display: inline-block;
    padding: 10px 20px;
    border: 1px solid #007bff; /* Blue border */
    background-color: #007bff;
    color: white; /* White text */
    text-decoration: none; /* Remove underline */
    border-radius: 5px;
    font-size: 16px;
    font-weight: bold;
    transition: background-color 0.3s, border-color 0.3s;
}

.back-button:hover {
    background-color: #0056b3; /* Darker blue on hover */
    border-color: #0056b3;
    box-shadow: 0 4px 8px rgba(0, 0, 0, 0.1);
}
</style>
</body>
</html>